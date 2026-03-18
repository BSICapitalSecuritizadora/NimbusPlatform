<?php

use App\Models\Document;
use App\Models\Emission;
use App\Models\Investor;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('hides unpublished documents from investor', function () {
    $investor = Investor::factory()->create();
    $document = Document::factory()->unpublished()->create();
    $document->investors()->attach($investor);

    $visible = Document::query()->visibleToInvestor($investor->id)->get();

    expect($visible)->toHaveCount(0);
});

it('hides published public documents from portal unless explicitly linked', function () {
    $investor = Investor::factory()->create();
    Document::factory()->public()->create();

    $visible = Document::query()->visibleToInvestor($investor->id)->get();

    expect($visible)->toHaveCount(0);
});

it('shows published document directly linked to investor', function () {
    $investor = Investor::factory()->create();
    $document = Document::factory()->published()->create();
    $document->investors()->attach($investor);

    $visible = Document::query()->visibleToInvestor($investor->id)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->id)->toBe($document->id);
});

it('shows published document linked to emission investor owns', function () {
    $investor = Investor::factory()->create();
    $emission = Emission::factory()->create();
    $investor->emissions()->attach($emission);

    $document = Document::factory()->published()->create();
    $document->emissions()->attach($emission);

    $visible = Document::query()->visibleToInvestor($investor->id)->get();

    expect($visible)->toHaveCount(1)
        ->and($visible->first()->id)->toBe($document->id);
});

it('hides published document linked to emission investor does not own', function () {
    $investor = Investor::factory()->create();
    $otherInvestor = Investor::factory()->create();
    $emission = Emission::factory()->create();
    $otherInvestor->emissions()->attach($emission);

    $document = Document::factory()->published()->create();
    $document->emissions()->attach($emission);

    $visible = Document::query()->visibleToInvestor($investor->id)->get();

    expect($visible)->toHaveCount(0);
});

it('hides published private document with no links to investor', function () {
    $investor = Investor::factory()->create();
    Document::factory()->published()->create(['is_public' => false]);

    $visible = Document::query()->visibleToInvestor($investor->id)->get();

    expect($visible)->toHaveCount(0);
});

it('returns only published and public documents for public site scope', function () {
    Document::factory()->public()->create();
    Document::factory()->published()->create(['is_public' => false]);
    Document::factory()->unpublished()->create();

    $publicDocs = Document::query()->visibleOnPublicSite()->get();

    expect($publicDocs)->toHaveCount(1);
});

it('does not duplicate documents when investor has both direct and emission links', function () {
    $investor = Investor::factory()->create();
    $emission = Emission::factory()->create();
    $investor->emissions()->attach($emission);

    $document = Document::factory()->public()->create();
    $document->investors()->attach($investor);
    $document->emissions()->attach($emission);

    $visible = Document::query()->visibleToInvestor($investor->id)->get();

    expect($visible)->toHaveCount(1);
});

it('orders documents by visibility priority: direct > emission > public', function () {
    $investor = Investor::factory()->create();
    $emission = Emission::factory()->create();
    $investor->emissions()->attach($emission);

    $publicDoc = Document::factory()->public()->create(['title' => 'Public Doc']);

    $emissionDoc = Document::factory()->published()->create(['title' => 'Emission Doc']);
    $emissionDoc->emissions()->attach($emission);

    $directDoc = Document::factory()->published()->create(['title' => 'Direct Doc']);
    $directDoc->investors()->attach($investor);

    $visible = Document::query()
        ->visibleToInvestor($investor->id)
        ->orderByVisibilityPriority($investor->id)
        ->get();

    expect($visible)->toHaveCount(2)
        ->and($visible[0]->title)->toBe('Direct Doc')
        ->and($visible[1]->title)->toBe('Emission Doc');
});
