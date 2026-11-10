<?php

namespace App\Filament\Support;

use App\Support\Money;
use Illuminate\Support\HtmlString;

/**
 * A thin bar split in proportion to a few amounts, with a legend underneath. Used as the
 * description of a stat card (e.g. what is still to receive, by how soon it is due). Colours
 * follow the user's palette (theme.css, .contiu-proportion); "danger" is the one status colour.
 */
final class ProportionBar
{
    /**
     * @param  list<array{label: string, cents: int, tone: 'danger'|'strong'|'medium'|'soft'|'clay'}>  $segments
     */
    public static function render(array $segments): HtmlString
    {
        $total = array_sum(array_column($segments, 'cents'));

        if ($total <= 0) {
            return new HtmlString('');
        }

        $bars = '';
        $legend = '';

        foreach ($segments as $segment) {
            if ($segment['cents'] <= 0) {
                continue;
            }

            $tone = e($segment['tone']);
            $width = round($segment['cents'] / $total * 100, 1);

            $bars .= "<i data-tone=\"{$tone}\" style=\"width: {$width}%\"></i>";
            $legend .= "<span><i data-tone=\"{$tone}\"></i>".e($segment['label']).' <strong>'.e(Money::format($segment['cents'])).'</strong></span>';
        }

        $summary = e(implode(', ', array_map(
            fn (array $segment): string => $segment['label'].' '.Money::format($segment['cents']),
            array_filter($segments, fn (array $segment): bool => $segment['cents'] > 0),
        )));

        return new HtmlString(
            "<span class=\"contiu-proportion\"><span class=\"contiu-proportion-bar\" role=\"img\" aria-label=\"{$summary}\">{$bars}</span>"
            ."<span class=\"contiu-proportion-legend\">{$legend}</span></span>",
        );
    }
}
