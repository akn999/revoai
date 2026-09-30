<?php

use Inertia\Testing\AssertableInertia as Assert;

test('embedded page is publicly accessible', function () {
    $this->get(route('embedded'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Embedded'));
});
