<?php

test('the root url redirects to documents', function () {
    $response = $this->get('/');

    $response->assertRedirect('/documents');
});
