<?php

namespace App\Contracts\Intelligence;

use App\Data\Intelligence\ConversationAnalysisInput;
use App\Data\Intelligence\ConversationAnalysisResult;

interface ConversationAnalyzer
{
    public function analyze(ConversationAnalysisInput $input): ConversationAnalysisResult;
}
