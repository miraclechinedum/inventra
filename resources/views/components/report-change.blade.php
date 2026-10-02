{{--
    A period-on-period movement: ▲ 11% vs prev 30d.

    The line is always rendered, whatever the data says, so the four KPI cards stay aligned. What
    changes is the wording, and every version of it is true:

      up / down      a real percentage, where both periods had activity
      0%             both periods had activity and it did not move
      New            something now where there was nothing before — not "100%", which would be a
                     number invented from a division by zero
      No change      nothing then and nothing now

    Used for Revenue, Orders and New customers, where an increase is good. The Outstanding card
    deliberately carries no arrow: more debt is not an improvement, and a green ▲ would say it was.
--}}
@props(['change'])

<p @class([
    'rpt-change',
    'is-up' => in_array($change['direction'], ['up', 'new'], true),
    'is-down' => $change['direction'] === 'down',
    'is-flat' => $change['direction'] === 'flat',
])>
    @if($change['direction'] === 'up')
        <span aria-hidden="true">▲</span><span class="ui-visually-hidden-until-focus">Up </span>
    @elseif($change['direction'] === 'down')
        <span aria-hidden="true">▼</span><span class="ui-visually-hidden-until-focus">Down </span>
    @endif
    {{ $change['label'] }}
</p>
