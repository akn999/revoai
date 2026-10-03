<?php

use App\Ai\Providers\ImageModelProvider;
use App\Ai\Providers\TextModelProvider;
use App\Ai\Testing\FakeImageModelProvider;
use App\Ai\Testing\FakeTextModelProvider;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Sleep;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
    ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

require_once __DIR__.'/Feature/Salla/helpers.php';

pest()->beforeEach(function () {
    config(['salla.webhook_secret' => SALLA_TEST_SECRET, 'salla.app_id' => '1234', 'salla.backoff' => [0]]);
    Carbon::setTestNow('2026-06-15 12:00:00');
    Http::preventStrayRequests();
    Http::fake(['api.salla.dev/admin/v2/*' => Http::response(['data' => [], 'pagination' => ['totalPages' => 1]])]);
    Sleep::fake();
})
    ->in('Feature/Salla');

pest()->beforeEach(function () {
    Http::preventStrayRequests();
    app()->instance(TextModelProvider::class, $text = new FakeTextModelProvider);
    app()->instance(ImageModelProvider::class, $image = new FakeImageModelProvider);
    $this->fakeText = $text;
    $this->fakeImage = $image;
})->in('Feature');
