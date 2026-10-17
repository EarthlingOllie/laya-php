<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Model\JevProfile;
use EarthlingOllie\SystemOneClassification\Model\LayaProfile;
use EarthlingOllie\SystemOneClassification\Model\ModelProfile;

it('describes Laya: 100 options per choice, described score levels, its checkpoints', function (): void {
    $profile = new LayaProfile;

    expect($profile)->toBeInstanceOf(ModelProfile::class)
        ->and($profile->name)->toBe('laya')
        ->and($profile->maxChoiceOptions)->toBe(100)
        ->and($profile->requiresScoreDescriptions)->toBeTrue()
        ->and($profile->models())->toBe([
            'english', 'multilingual', 'typed-decisions',
            'decisions', 'default', 'en', 'laya', 'laya-multilingual', 'laya-typed-decisions',
            'ml', 'multi', 'typed', 'typed_decisions',
        ]);
});

it('allows 255 options per choice on a Laya server with tournament grouping', function (): void {
    expect((new LayaProfile(tournamentGrouping: true))->maxChoiceOptions)->toBe(255);
});

it('accepts the extra model values a Laya server is configured with, after its own', function (): void {
    $profile = new LayaProfile(extraModels: ['acme/laya-finetune']);

    expect($profile->models())->toBe([...(new LayaProfile)->models(), 'acme/laya-finetune']);
});

it('describes Jev: 255 options per choice, undescribed score levels allowed, only configured models', function (): void {
    $profile = new JevProfile;

    expect($profile)->toBeInstanceOf(ModelProfile::class)
        ->and($profile->name)->toBe('jev')
        ->and($profile->maxChoiceOptions)->toBe(255)
        ->and($profile->requiresScoreDescriptions)->toBeFalse()
        ->and($profile->models())->toBe([])
        ->and((new JevProfile(['jev-1']))->models())->toBe(['jev-1']);
});

it('cannot be changed after construction', function (ModelProfile $profile): void {
    expect(fn () => $profile->maxChoiceOptions = 1)->toThrow(Error::class);
})->with([
    'laya' => fn (): ModelProfile => new LayaProfile,
    'jev' => fn (): ModelProfile => new JevProfile,
]);

it('accepts a model when laya-serve would: any case of a checkpoint, alias or extra model', function (): void {
    $profile = new LayaProfile(extraModels: ['Acme/Finetune']);

    expect($profile->acceptsModel('english'))->toBeTrue()
        ->and($profile->acceptsModel('Typed_Decisions'))->toBeTrue()
        ->and($profile->acceptsModel('acme/finetune'))->toBeTrue()
        ->and($profile->acceptsModel('ACME/FINETUNE'))->toBeTrue()
        ->and($profile->acceptsModel('someone/else'))->toBeFalse()
        ->and($profile->acceptsModel('englis'))->toBeFalse();
});

it('accepts only the exact models a Jev connection lists', function (): void {
    $profile = new JevProfile(['jev-1']);

    expect($profile->acceptsModel('jev-1'))->toBeTrue()
        ->and($profile->acceptsModel('Jev-1'))->toBeFalse()
        ->and((new JevProfile)->acceptsModel('jev-1'))->toBeFalse();
});
