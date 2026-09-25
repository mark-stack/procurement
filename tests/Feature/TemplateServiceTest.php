<?php

use App\Services\TemplateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Exceptions;

uses(RefreshDatabase::class);

function garbageUpload(): UploadedFile
{
    $path = tempnam(sys_get_temp_dir(), 'garbage').'.xlsx';
    file_put_contents($path, 'this is not a spreadsheet');

    return new UploadedFile($path, 'broken.xlsx', 'application/vnd.ms-excel', null, true);
}

function exampleUpload(): UploadedFile
{
    return new UploadedFile(
        base_path('public/examples/material_list.xlsx'),
        'material_list.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        null,
        true
    );
}

it('would be a disaster if an unreadable upload threw instead of being reported', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, true, true);
    Auth::login($user);

    $invalid = (new TemplateService)->invalidFiles([garbageUpload()]);

    expect($invalid)->toBe(['broken.xlsx']);
});

it('would be a disaster if a valid template was reported as invalid', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, true, true);
    Auth::login($user);

    $invalid = (new TemplateService)->invalidFiles([exampleUpload()]);

    expect($invalid)->toBe([]);
});

/**
 * A malformed template config makes our own detection code blow up on a file
 * that is perfectly readable. That used to be swallowed and reported to the
 * user as "did this template change?".
 */
function breakTemplateDetection(): void
{
    config(['TableTemplates' => [
        ['ownerDomain' => null], //missing ExpectedHeadingLabels
    ]]);
}

it('would be a disaster if a bug in detection was reported as a bad template', function () {
    $business = createBusiness('gmail', true);
    $admin = createUser(1, $business, true, true);
    Auth::login($admin);
    breakTemplateDetection();

    //Admin sees the real exception rather than a misleading message
    expect(fn () => (new TemplateService)->invalidFiles([exampleUpload()]))
        ->toThrow(ErrorException::class);
});

it('would be a disaster if a bug in detection was never logged', function () {
    Exceptions::fake();

    $business = createBusiness('gmail', true);
    $user = createUser(2, $business, false, true);
    Auth::login($user);
    breakTemplateDetection();

    //Regular users still get the friendly path...
    $invalid = (new TemplateService)->invalidFiles([exampleUpload()]);
    expect($invalid)->toBe(['material_list.xlsx']);

    //...but the underlying bug is reported rather than silently discarded
    Exceptions::assertReported(ErrorException::class);
});

it('would be a disaster if temp uploads were left behind on disk', function () {
    $business = createBusiness('gmail', true);
    $user = createUser(1, $business, true, true);
    Auth::login($user);

    $before = glob(storage_path('app/private/uploads/*'));

    (new TemplateService)->invalidFiles([exampleUpload(), garbageUpload()]);

    $after = glob(storage_path('app/private/uploads/*'));

    expect($after)->toBe($before);
});

it('would be a disaster if a rethrown error leaked the temp upload', function () {
    $business = createBusiness('gmail', true);
    $admin = createUser(1, $business, true, true);
    Auth::login($admin);
    breakTemplateDetection();

    $before = glob(storage_path('app/private/uploads/*'));

    try {
        (new TemplateService)->invalidFiles([exampleUpload()]);
    } catch (ErrorException $e) {
        //Expected - the admin path rethrows
    }

    expect(glob(storage_path('app/private/uploads/*')))->toBe($before);
});
