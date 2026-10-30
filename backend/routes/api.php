<?php

use App\Http\Controllers\QueryController;
use Illuminate\Support\Facades\Route;

Route::post('/query', QueryController::class);
