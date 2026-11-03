-- Totals of the business metrics (KPIs) of one application, for the master item apm.kpi.totals.get of "APM business
-- metrics by ClickHouse HTTP". scripts/build-kpi-template.py puts this query in the template.
--
-- Computed from ClickHouse, not from the history of Zabbix, so they are complete as soon as the items exist. One row per
-- metric (dim_key = '') and one per value of each attribute, with:
--   last_hour, last_24h, today, yesterday   increase of counters (and count of histograms) in each window; days are
--                                            those of the {tz} time zone; ClickHouse keeps 3 days by default
--   counter                                  the value the counter reports now (cumulative series, summed over the
--                                            instances seen in the last 5 minutes): what the application says "since
--                                            it started"; null for delta series
--   sum_today, sum_yesterday, avg_today      histograms: sum and average of the measurements
--   share_today, rank_today                  per attribute value: % of today within its attribute, and its position
-- The windows end at the last closed minute, {delay} seconds late, like the per-minute item.
WITH
    toDateTime(toStartOfMinute(now()) - {delay:UInt32}) AS t1,
    toDateTime(toStartOfDay(t1, {tz:String})) AS d0,
    toDateTime(toStartOfDay(d0 - 3600, {tz:String})) AS y0,
    -- Points before yesterday give the value each series had when yesterday started.
    y0 - 900 AS tw,
    (x -> replaceRegexpAll(x, '[''"\\\\]', '')) AS clean
SELECT
    service, metric, kind, any(unit) AS unit, dim.1 AS dim_key, dim.2 AS dim_value,
    round(sum(last_hour), 6) AS last_hour, round(sum(last_24h), 6) AS last_24h,
    round(sum(today), 6) AS today, round(sum(yesterday), 6) AS yesterday,
    if(max(delta), NULL, round(sum(counter), 6)) AS counter,
    round(sum(sum_today), 6) AS sum_today, round(sum(sum_yesterday), 6) AS sum_yesterday,
    if(today > 0, round(sum_today / today, 6), 0) AS avg_today,
    if(sum(today) OVER w > 0, round(today * 100 / sum(today) OVER w, 3), 0) AS share_today,
    row_number() OVER (w ORDER BY today DESC, dim_value) AS rank_today
FROM
(
    SELECT
        service, metric, kind, unit, attrs, delta,
        -- Increase at each point of a cumulative series: a reset (lower value) counts the new value, and a series that
        -- starts inside the window counts its first value. Delta series are their own increase.
        if(delta, vs, arrayMap((v, pv, st, i) -> if(i = 1, if(st >= tw, v, 0), if(v >= pv, v - pv, v)),
            vs, arrayPopBack(arrayPushFront(vs, 0)), starts, arrayEnumerate(vs))) AS dv,
        if(delta, ss, arrayMap((v, pv, st, i) -> if(i = 1, if(st >= tw, v, 0), if(v >= pv, v - pv, v)),
            ss, arrayPopBack(arrayPushFront(ss, 0)), starts, arrayEnumerate(ss))) AS ds,
        arraySum(arrayMap((d, t) -> if(t >= t1 - 3600, d, 0), dv, ts)) AS last_hour,
        arraySum(arrayMap((d, t) -> if(t >= t1 - 86400, d, 0), dv, ts)) AS last_24h,
        arraySum(arrayMap((d, t) -> if(t >= d0, d, 0), dv, ts)) AS today,
        arraySum(arrayMap((d, t) -> if(t >= y0 AND t < d0, d, 0), dv, ts)) AS yesterday,
        -- Instances that stopped reporting more than 5 minutes ago (e.g. before a restart) do not count.
        if(ts[-1] >= t1 - 300, vs[-1], 0) AS counter,
        if(kind = 'histogram', arraySum(arrayMap((d, t) -> if(t >= d0, d, 0), ds, ts)), 0) AS sum_today,
        if(kind = 'histogram', arraySum(arrayMap((d, t) -> if(t >= y0 AND t < d0, d, 0), ds, ts)), 0) AS sum_yesterday
    FROM
    (
        SELECT
            clean(ServiceName) AS service, clean(MetricName) AS metric, kind, Attributes AS attrs,
            if(match(any(MetricUnit), '^([{].*[}]|1)$'), '', any(MetricUnit)) AS unit, max(temporality = 1) AS delta,
            arraySort(p -> p.1, groupArray((TimeUnix, v, s, StartTimeUnix))) AS pts,
            arrayMap(p -> p.1, pts) AS ts, arrayMap(p -> p.2, pts) AS vs, arrayMap(p -> p.3, pts) AS ss,
            arrayMap(p -> p.4, pts) AS starts
        FROM
        (
            SELECT ServiceName, MetricName, MetricUnit, Attributes, ResourceAttributes,
                   TimeUnix, StartTimeUnix, Value AS v, 0 AS s, AggregationTemporality AS temporality,
                   if(IsMonotonic, 'counter', 'updown') AS kind
            FROM {db:Identifier}.otel_metrics_sum
            WHERE TimeUnix >= tw AND TimeUnix < t1
            UNION ALL
            SELECT ServiceName, MetricName, MetricUnit, Attributes, ResourceAttributes,
                   TimeUnix, StartTimeUnix, toFloat64(Count), Sum, AggregationTemporality, 'histogram'
            FROM {db:Identifier}.otel_metrics_histogram
            WHERE TimeUnix >= tw AND TimeUnix < t1
        )
        WHERE ResourceAttributes['service.namespace'] = {ns:String}
          AND match(MetricName, {metric_matches:String})
          AND ({metric_not_matches:String} = '' OR NOT match(MetricName, {metric_not_matches:String}))
        GROUP BY ServiceName, MetricName, kind, attrs, ResourceAttributes['service.instance.id']
    )
)
-- One row for the total of the metric and one per attribute value (store, hotel, currency...).
ARRAY JOIN arrayConcat([('', '')], arrayMap(k -> (clean(k), clean(attrs[k])),
    arrayFilter(k -> {dim_not_matches:String} = '' OR NOT match(k, {dim_not_matches:String}), mapKeys(attrs)))) AS dim
GROUP BY service, metric, kind, dim
WINDOW w AS (PARTITION BY service, metric, kind, dim_key)
ORDER BY dim_key != '', service, metric, dim_key, rank_today
LIMIT {limit:UInt32}
FORMAT JSON
SETTINGS output_format_json_quote_64bit_integers = 0
