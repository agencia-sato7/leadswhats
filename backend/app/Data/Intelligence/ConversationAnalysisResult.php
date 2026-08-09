<?php

namespace App\Data\Intelligence;

use InvalidArgumentException;

final readonly class ConversationAnalysisResult
{
    /**
     * @param string[] $objections
     * @param string[] $positivePoints
     * @param string[] $errors
     * @param array<string, mixed> $commercialData
     * @param array<string, int|float> $criteriaScores
     */
    public function __construct(
        public int $score,
        public string $summary,
        public ?string $intent,
        public array $objections,
        public array $positivePoints,
        public array $errors,
        public string $improvementSuggestion,
        public array $commercialData,
        public array $criteriaScores,
        public ?int $recommendedKanbanColumnId,
        public string $classificationReason,
        public float $confidence,
        public string $promptVersion,
        public ?string $modelProvider = null,
        public ?string $modelName = null,
    ) {
        if ($score < 0 || $score > 100) {
            throw new InvalidArgumentException('O score retornado pelo analisador deve estar entre 0 e 100.');
        }

        if ($confidence < 0 || $confidence > 1) {
            throw new InvalidArgumentException('A confiança retornada pelo analisador deve estar entre 0 e 1.');
        }

        if (trim($summary) === '') {
            throw new InvalidArgumentException('O analisador deve retornar um resumo.');
        }
    }
}
