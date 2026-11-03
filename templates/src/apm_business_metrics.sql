-- Business metrics (KPIs) of one application, for the master item of "APM business metrics by ClickHouse HTTP".
-- scripts/build-kpi-template.py puts this query in templates/apm_business_metrics_clickhouse_http.json.
--
-- One row per metric (dim_key = '') and one per value of each of its attributes (dim_key = 'shop.store', dim_value =
-- 'Centro'), for the last closed minute [t0, t1):
--   counter    increase in the minute (cumulative series: differences between points; delta series: their sum)
--   updown     current value (up-down counter, e.g. rooms occupied), of the instances seen in the last 5 minutes
--   gauge      current value, of the instances seen in the last 5 minutes
--   histogram  increase of the count (value, count) and of the sum (sum) in the minute
-- Units are those of the metric (USD, s, By); annotations such as {order} and the unit 1 are dropped, the metric name
-- already says what is counted. Instances and attribute sets are added up. Series seen in the last {window} minutes return 0 when quiet, so the
-- items keep a value every minute and the sum of their history over a period is the total of that period.
WITH
    toDateTime(toStartOfMinute(now()) - {delay:UInt32}) AS t1,
    t1 - 60 AS t0,
    t1 - {window:UInt32} * 60 AS tw,
    -- Quotes and backslashes are removed from names: they would break the item keys and JSONPath filters.
    (x -> replaceRegexpAll(x, '[''"\\\\]', '')) AS clean
SELECT
    service, metric, kind, any(unit) AS unit, any(description) AS description, dim.1 AS dim_key, dim.2 AS dim_value,
    round(sum(value), 6) AS value, round(sum(cnt), 6) AS count, round(sum(total), 6) AS sum, round(sum(win), 6) AS window_total
FROM
(
    SELECT
        service, metric, kind, unit, description, attrs,
        arrayMap(p -> p.1, pts) AS ts,
        arrayMap(p -> p.2, pts) AS vs,
        arrayMap(p -> p.3, pts) AS ss,
        arrayMap(p -> p.4, pts) AS starts,
        -- Increase of a cumulative series in [t0, t1): differences between consecutive points, a reset (lower value)
        -- counts the new value, and a series that starts inside the window counts its first value.
        arrayMap((v, pv, st, i) -> if(i = 1, if(st >= tw, v, 0), if(v >= pv, v - pv, v)),
            vs, arrayPopBack(arrayPushFront(vs, 0)), starts, arrayEnumerate(vs)) AS dv,
        arrayMap((v, pv, st, i) -> if(i = 1, if(st >= tw, v, 0), if(v >= pv, v - pv, v)),
            ss, arrayPopBack(arrayPushFront(ss, 0)), starts, arrayEnumerate(ss)) AS ds,
        arraySum(arrayMap((d, t) -> if(t >= t0, d, 0), if(delta, vs, dv), ts)) AS inc_v,
        arraySum(arrayMap((d, t) -> if(t >= t0, d, 0), if(delta, ss, ds), ts)) AS inc_s,
        -- Current values count only for instances that reported in the last 5 minutes (not one that stopped).
        if(kind IN ('gauge', 'updown'), if(ts[-1] >= t1 - 300, vs[-1], 0), inc_v) AS value,
        if(kind = 'histogram', inc_v, 0) AS cnt,
        if(kind = 'histogram', inc_s, 0) AS total,
        if(kind IN ('gauge', 'updown'), arrayMax(vs), arraySum(if(delta, vs, dv))) AS win
    FROM
    (
        SELECT
            clean(ServiceName) AS service, clean(MetricName) AS metric, kind, Attributes AS attrs,
            if(match(any(MetricUnit), '^([{].*[}]|1)$'), '', any(MetricUnit)) AS unit, any(MetricDescription) AS description, max(temporality = 1) AS delta,
            arraySort(p -> p.1, groupArray((TimeUnix, v, s, StartTimeUnix))) AS pts
        FROM
        (
            SELECT ServiceName, MetricName, MetricUnit, MetricDescription, Attributes, ResourceAttributes,
                   TimeUnix, StartTimeUnix, Value AS v, 0 AS s, AggregationTemporality AS temporality,
                   if(IsMonotonic, 'counter', 'updown') AS kind
            FROM {db:Identifier}.otel_metrics_sum
            WHERE TimeUnix >= tw AND TimeUnix < t1
            UNION ALL
            SELECT ServiceName, MetricName, MetricUnit, MetricDescription, Attributes, ResourceAttributes,
                   TimeUnix, StartTimeUnix, Value, 0, 0, 'gauge'
            FROM {db:Identifier}.otel_metrics_gauge
            WHERE TimeUnix >= tw AND TimeUnix < t1
            UNION ALL
            SELECT ServiceName, MetricName, MetricUnit, MetricDescription, Attributes, ResourceAttributes,
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
ORDER BY dim_key != '', service, metric, dim_key, window_total DESC
LIMIT {values_limit:UInt32} BY service, metric, kind, dim_key
LIMIT {limit:UInt32}
FORMAT JSON
SETTINGS output_format_json_quote_64bit_integers = 0
