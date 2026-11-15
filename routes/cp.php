<?php

declare(strict_types=1);

use bensomething\wahlberg\Http\Controllers\EntryLinksController;
use Illuminate\Support\Facades\Route;

// Under the CP trigger, behind Craft's CP auth. Not an action route: those are also
// served on the front end, without it.
Route::get('wahlberg/entries', EntryLinksController::class)
    ->name('wahlberg.entries');
