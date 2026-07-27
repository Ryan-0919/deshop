<?php
namespace app\integration\wangdian;

trait WangDianBatchPushWorkflow
{
    private function pushBatchRecords(
        WangDianBatchPushDefinition $definition,
        array $records,
        array $attempts,
        int $now,
        array &$result,
        bool $isIndividualRetry = false
    ): void
    {
        try {
            $response = $this->client()->request($definition->endpoint(), array_merge($definition->businessParams(), [
                $definition->payloadName()=>json_encode($records, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            ]));
            $attemptedIds = array_column($attempts, $definition->attemptIdField());
            $errors = WangDianBatchResponse::errors(
                $response,
                $attemptedIds,
                $definition->errorIdFields(),
                $definition->defaultError()
            );
            if(!WangDianBatchResponse::countMatches(
                $response,
                $attemptedIds,
                $errors,
                $definition->allowImplicitSingleSuccess() && $isIndividualRetry && count($attempts) === 1
            ))
            {
                if($isIndividualRetry)
                {
                    throw new WangDianApiException(
                        'Wangdiantong did not confirm acceptance of '.$attemptedIds[0].'.',
                        $response
                    );
                }
                foreach($attempts as $index=>$attempt)
                {
                    $this->pushBatchRecords(
                        $definition,
                        [$records[$index]],
                        [$attempt],
                        $now,
                        $result,
                        true
                    );
                }
                return;
            }

            $summary = $this->responseSummary($response);
            foreach($attempts as $attempt)
            {
                $recordId = (string)$attempt[$definition->attemptIdField()];
                $error = $errors[$recordId] ?? $errors['*'] ?? '';
                if($error === '')
                {
                    $this->store()->markSuccess($definition->businessType(), $attempt['key'], $summary, $now);
                    $result['success']++;
                } else {
                    $this->store()->markFailure($definition->businessType(), $attempt['key'], $error, $now, $summary);
                    $result['failed']++;
                }
            }
        } catch(\Throwable $e) {
            foreach($attempts as $attempt)
            {
                $this->store()->markFailure(
                    $definition->businessType(),
                    $attempt['key'],
                    $this->safeError($e),
                    $now,
                    $this->exceptionResponseSummary($e)
                );
                $result['failed']++;
            }
            if(!$isIndividualRetry)
            {
                $result['message'] = $this->safeError($e);
            }
        }
    }
}
