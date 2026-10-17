<?php

declare(strict_types=1);

use EarthlingOllie\SystemOneClassification\Domain\Question;
use EarthlingOllie\SystemOneClassification\Domain\Question\QuestionHandle;
use EarthlingOllie\SystemOneClassification\Exceptions\UnknownQuestion;
use EarthlingOllie\SystemOneClassification\Result\Abstention;
use EarthlingOllie\SystemOneClassification\Result\Answer\Answer;
use EarthlingOllie\SystemOneClassification\Result\Answer\YesNoAnswer;
use EarthlingOllie\SystemOneClassification\Result\Result;

/**
 * @param  QuestionHandle<Answer>  $question
 * @return array{question: QuestionHandle<Answer>, answer: Answer}
 */
function answered(QuestionHandle $question, Answer $answer): array
{
    return ['question' => $question, 'answer' => $answer];
}

function churnHandle(): QuestionHandle
{
    return Question::yesNo('churn', 'Does the customer threaten to cancel?');
}

it('returns the answer to a question it holds, read by the question handle', function (): void {
    $churn = churnHandle();
    $refund = Question::yesNo('refund', 'Does the customer ask for a refund?');
    $churnAnswer = new YesNoAnswer(0.91);
    $refundAnswer = new YesNoAnswer(0.12);

    $result = new Result(['churn' => answered($churn, $churnAnswer), 'refund' => answered($refund, $refundAnswer)]);

    expect($result->get($churn))->toBe($churnAnswer)
        ->and($result->get($refund))->toBe($refundAnswer);
});

it('reads an answer with an equal handle built separately', function (): void {
    $answer = new YesNoAnswer(0.91);
    $result = new Result(['churn' => answered(churnHandle(), $answer)]);

    expect($result->get(Question::yesNo('churn', 'Does the customer threaten to cancel?')))->toBe($answer);
});

it('throws a clear exception for a question that was not part of the decision', function (): void {
    $result = new Result(['churn' => answered(churnHandle(), new YesNoAnswer(0.91))]);

    $result->get(Question::yesNo('refund', 'Does the customer ask for a refund?'));
})->throws(UnknownQuestion::class, "The result has no answer for the question 'refund': it was not part of the decision. Asked: 'churn'.");

it('names no questions when the result is empty', function (): void {
    (new Result([]))->get(Question::yesNo('refund', 'Does the customer ask for a refund?'));
})->throws(UnknownQuestion::class, "The result has no answer for the question 'refund': it was not part of the decision. Asked: none.");

it('refuses a handle that shares the key but asks with other instructions', function (): void {
    $result = new Result(['churn' => answered(churnHandle(), new YesNoAnswer(0.91))]);

    $result->get(Question::yesNo('churn', 'Is the customer happy with the product?'));
})->throws(UnknownQuestion::class, "The result's question 'churn' is not the one passed to get(): it was asked with other instructions or settings, so the handle is from another decision.");

it('refuses a handle of another class even when its key and instructions match', function (): void {
    $lookalike = new class implements QuestionHandle
    {
        public string $key = 'churn';

        public string $instructions = 'Does the customer threaten to cancel?';

        public function answerType(): string
        {
            return YesNoAnswer::class;
        }
    };

    expect((array) $lookalike)->toBe((array) churnHandle())
        ->and(fn () => (new Result(['churn' => answered(churnHandle(), new YesNoAnswer(0.91))]))->get($lookalike))
        ->toThrow(UnknownQuestion::class, "The result's question 'churn' is not the one passed to get()");
});

it('refuses a held answer of another type than its handle expects', function (): void {
    $other = new class implements Answer
    {
        public bool $abstained = false;

        public Abstention $abstention = Abstention::NotRequested;

        public ?float $abstentionThreshold = null;
    };
    $handle = new class($other::class) implements QuestionHandle
    {
        public string $key = 'churn';

        public string $instructions = 'Pick one.';

        /** @param class-string<Answer> $type */
        public function __construct(private string $type) {}

        public function answerType(): string
        {
            return $this->type;
        }
    };

    expect(fn () => (new Result(['churn' => answered($handle, new YesNoAnswer(0.91))]))->get($handle))
        ->toThrow(UnknownQuestion::class, "The answer to 'churn' is a ".YesNoAnswer::class.', not the '.$other::class.' this question expects: it was not part of the decision.');
});

it('lists every asked key in the message', function (): void {
    (new Result([
        'churn' => answered(churnHandle(), new YesNoAnswer(0.9)),
        'angry' => answered(Question::yesNo('angry', 'Is the customer angry?'), new YesNoAnswer(0.1)),
    ]))->get(Question::yesNo('refund', 'Does the customer ask for a refund?'));
})->throws(UnknownQuestion::class, "Asked: 'churn', 'angry'.");
