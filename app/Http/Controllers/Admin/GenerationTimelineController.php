<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Generation;
use App\Reports\GenerationTimeline;
use Inertia\Inertia;
use Inertia\Response;

class GenerationTimelineController extends Controller
{
    public function __invoke(Generation $generation, GenerationTimeline $timeline): Response
    {
        return Inertia::render('Admin/Generation', $timeline->build($generation, payloads: true));
    }
}
