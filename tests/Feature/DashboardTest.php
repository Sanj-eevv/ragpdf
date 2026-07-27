<?php

test('the dashboard page can be visited', function () {
    $response = $this->get(route('dashboard'));
    $response->assertOk();
});
