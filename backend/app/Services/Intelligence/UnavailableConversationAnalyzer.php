<?php

namespace App\Services\Intelligence;

use App\Contracts\Intelligence\ConversationAnalyzer;
use App\Data\Intelligence\ConversationAnalysisInput;
use App\Data\Intelligence\ConversationAnalysisResult;
use App\Exceptions\ConversationAnalyzerUnavailableException;

class UnavailableConversationAnalyzer implements ConversationAnalyzer
{
    public function analyze(ConversationAnalysisInput $input): ConversationAnalysisResult
    {
        throw new ConversationAnalyzerUnavailableException(
            'Nenhum provedor de Conversation Intelligence foi configurado para este ambiente.'
        );
    }
}
