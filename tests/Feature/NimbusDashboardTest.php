<?php

use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('registers a dedicated Nimbus dashboard route inside the admin panel', function () {
    $this->get(route('filament.admin.pages.nimbus-dashboard'))
        ->assertRedirect('/admin/login');
});
