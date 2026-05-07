<?php

namespace App\Ai\Agents;

use Laravel\Ai\Attributes\Model;
use Laravel\Ai\Attributes\Provider;
use Laravel\Ai\Attributes\Temperature;
use Laravel\Ai\Attributes\Timeout;
use Laravel\Ai\Contracts\Agent;
use Laravel\Ai\Enums\Lab;
use Laravel\Ai\Promptable;
use Stringable;

#[Provider(Lab::Gemini)]
#[Model('gemini-2.5-flash')]
#[Temperature(0.1)]
#[Timeout(45)]
class HalalProductGeminiAgent implements Agent
{
    use Promptable;

    public function instructions(): Stringable|string
    {
        return 'You are the AI layer for a halal product checker Laravel app. 
Return exactly what the application prompt asks for. 
If the prompt asks for JSON, return valid JSON only. 
Do not use markdown. 
Do not add explanations outside the requested format.';
    }
}