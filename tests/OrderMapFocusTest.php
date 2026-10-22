<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/**
 * The map windows of an order (a point or an area, a route, a search area, a
 * restricted area) open next to the mission, not on all of Greece.
 *
 * They used to start from `setView(missionLocation ? [lat, lng] : Greece, 13 : 7)`
 * once, when the map was first created: a mission with no point of its own opened
 * on the whole country, and a window opened a second time stayed wherever the
 * last order had left it. orderMapView() / applyOrderMapView() decide it now, on
 * every open. This pins that every window uses them (the logic itself is a few
 * lines of Leaflet, checked in a real browser).
 */
final class OrderMapFocusTest extends TestCase
{
    private string $page;

    protected function setUp(): void
    {
        $text = file_get_contents(__DIR__ . '/../war-room.php');
        $this->assertNotFalse($text);
        $this->page = str_replace("\r\n", "\n", (string) $text);
    }

    public function testTheViewIsDecidedInOnePlace(): void
    {
        $this->assertSame(1, substr_count($this->page, 'function orderMapView() {'));
        $this->assertSame(1, substr_count($this->page, 'function applyOrderMapView(orderMap) {'));
        // The mission's own point first, at street level; Greece only as the very last resort.
        $start = (int) strpos($this->page, 'function orderMapView() {');
        $body = substr($this->page, $start, 1800);
        $this->assertLessThan(strpos($body, 'pts.length'), strpos($body, 'missionLocation.lat'));
        $this->assertLessThan(strpos($body, '37.97, 23.73'), strpos($body, 'pts.length'));
        $this->assertStringContainsString('zoom: 15', $body);
    }

    public function testEveryOrderWindowAsksForIt(): void
    {
        foreach (['dispatchMap', 'routeMap', 'composerMap'] as $mapVar) {
            $this->assertStringContainsString("applyOrderMapView($mapVar);", $this->page, "$mapVar does not open next to the mission");
        }
        // composerMap serves two windows (search area, restricted area).
        $this->assertSame(2, substr_count($this->page, 'applyOrderMapView(composerMap);'));
    }

    public function testASeedThatPlacesItsOwnPointsIsNotOverridden(): void
    {
        $this->assertStringContainsString('if (!(pendingDispatchSeed && pendingDispatchSeed.points && pendingDispatchSeed.points.length)) applyOrderMapView(dispatchMap);', $this->page);
        $this->assertStringContainsString('if (!(pendingRouteSeed && pendingRouteSeed.points && pendingRouteSeed.points.length)) applyOrderMapView(routeMap);', $this->page);
    }

    public function testItIsAStartingPositionNotAnAnimation(): void
    {
        $start = (int) strpos($this->page, 'function applyOrderMapView(orderMap) {');
        $body = substr($this->page, $start, 700);
        $this->assertSame(2, substr_count($body, 'animate: false'));
    }
}
