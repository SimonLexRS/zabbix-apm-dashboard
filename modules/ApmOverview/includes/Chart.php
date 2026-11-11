<?php declare(strict_types = 0);

namespace Modules\ApmOverview\Includes;

use CDiv,
	CSpan,
	CTag;

/**
 * Time series as an inline SVG, drawn by the server: the lines of the period, the same hours of earlier days as thinner
 * dashed lines, and vertical marks for events (deployments). The values of each point are in the title of its column,
 * which the browser shows on hover; nothing here needs a script.
 */
class Chart {

	private const WIDTH = 560;
	private const HEIGHT = 130;
	private const LEFT = 4;
	private const RIGHT = 4;
	private const TOP = 8;
	private const BOTTOM = 16;

	/**
	 * @param string $title    Shown above the chart.
	 * @param int[]  $times    Start of each point, Unix time.
	 * @param array  $series   [['label' => string, 'class' => string, 'values' => float[], 'dashed' => bool], ...];
	 *                         the first ones are drawn on top.
	 * @param callable $format Formats a value for the axis and the titles.
	 * @param array  $marks    [['clock' => int, 'label' => string], ...] vertical marks.
	 */
	public static function timeseries(string $title, array $times, array $series, callable $format, array $marks = []): CDiv {
		$count = count($times);
		$max = self::niceMax(max(array_merge([0], ...array_map(
			static fn(array $line): array => array_map('floatval', $line['values']), $series
		))));

		$inner_w = self::WIDTH - self::LEFT - self::RIGHT;
		$inner_h = self::HEIGHT - self::TOP - self::BOTTOM;
		$x = static fn(int $i): float => self::LEFT + ($count > 1 ? $i / ($count - 1) : 0.5) * $inner_w;
		$y = static fn(float $value): float => self::TOP + $inner_h - ($max > 0 ? min(1, $value / $max) : 0) * $inner_h;

		$svg = (new CTag('svg', true))
			->addClass('apm-chart-svg')
			->setAttribute('viewBox', '0 0 '.self::WIDTH.' '.self::HEIGHT)
			->setAttribute('preserveAspectRatio', 'none')
			->setAttribute('role', 'img')
			->setAttribute('aria-label', $title);

		// Three quiet horizontal lines: zero, half and the top of the scale.
		foreach ([0, 0.5, 1] as $share) {
			$svg->addItem(
				(new CTag('line', true))
					->addClass('apm-chart-grid')
					->setAttribute('x1', (string) self::LEFT)
					->setAttribute('x2', (string) (self::WIDTH - self::RIGHT))
					->setAttribute('y1', sprintf('%.1f', $y($max * $share)))
					->setAttribute('y2', sprintf('%.1f', $y($max * $share)))
			);
		}

		foreach (array_reverse($series) as $line) {
			$points = [];

			foreach (array_values($line['values']) as $i => $value) {
				$points[] = sprintf('%.1f,%.1f', $x($i), $y((float) $value));
			}

			$svg->addItem(
				(new CTag('polyline', true))
					->addClass('apm-chart-line')
					->addClass($line['class'])
					->addClass(($line['dashed'] ?? false) ? 'apm-chart-dashed' : null)
					->setAttribute('points', implode(' ', $points))
			);
		}

		if ($count > 0) {
			$first = $times[0];
			$span = max(1, end($times) - $first);

			foreach ($marks as $mark) {
				if ($mark['clock'] < $first || $mark['clock'] > $first + $span) {
					continue;
				}

				$mx = self::LEFT + ($mark['clock'] - $first) / $span * $inner_w;

				$svg->addItem(
					(new CTag('line', true))
						->addClass('apm-chart-mark')
						->setAttribute('x1', sprintf('%.1f', $mx))
						->setAttribute('x2', sprintf('%.1f', $mx))
						->setAttribute('y1', (string) self::TOP)
						->setAttribute('y2', (string) (self::HEIGHT - self::BOTTOM))
						->addItem(new CTag('title', true, $mark['label']))
				);
			}
		}

		// One invisible column per point, with every value of that moment in its title.
		$column = $count > 0 ? $inner_w / $count : 0;

		foreach ($times as $i => $clock) {
			$lines = [zbx_date2str(DATE_TIME_FORMAT, $clock)];

			foreach ($series as $line) {
				$lines[] = $line['label'].': '.$format((float) (array_values($line['values'])[$i] ?? 0));
			}

			$svg->addItem(
				(new CTag('rect', true))
					->addClass('apm-chart-hover')
					->setAttribute('x', sprintf('%.1f', self::LEFT + $i * $column))
					->setAttribute('y', (string) self::TOP)
					->setAttribute('width', sprintf('%.1f', $column))
					->setAttribute('height', (string) $inner_h)
					->addItem(new CTag('title', true, implode("\n", $lines)))
			);
		}

		$legend = (new CDiv())->addClass('apm-chart-legend');

		foreach ($series as $line) {
			$legend->addItem(
				(new CSpan($line['label']))
					->addClass('apm-chart-key')
					->addClass($line['class'])
					->addClass(($line['dashed'] ?? false) ? 'apm-chart-dashed' : null)
			);
		}

		return (new CDiv([
			(new CDiv([
				new CTag('strong', true, $title),
				(new CSpan(sprintf(I18n::t('scale: 0 to %s'), $format($max))))->addClass(ZBX_STYLE_GREY)
			]))->addClass('apm-chart-head'),
			$svg,
			(new CDiv([
				new CSpan($count > 0 ? OverviewHtml::formatTime($times[0]) : ''),
				$legend,
				new CSpan($count > 0 ? OverviewHtml::formatTime(end($times)) : '')
			]))->addClass('apm-chart-foot')
		]))->addClass('apm-chart');
	}

	/**
	 * Top of the scale: the next 1, 2 or 5 times a power of ten at or above the largest value.
	 */
	public static function niceMax(float $value): float {
		if ($value <= 0) {
			return 0.0;
		}

		$power = 10 ** floor(log10($value));

		foreach ([1, 2, 5, 10] as $step) {
			if ($value <= $step * $power) {
				return $step * $power;
			}
		}

		return 10 * $power;
	}
}
