<?php
namespace app\integration\wangdian;

final class WangDianBatchResponse
{
    public static function errors(
        array $response,
        array $attemptedIds,
        array $identifierFields,
        string $defaultMessage
    ): array
    {
        $errors = $response['errors'] ?? [];
        if($errors === [] || $errors === null)
        {
            return [];
        }
        if(!is_array($errors))
        {
            return ['*'=>'Wangdiantong batch response contained malformed errors.'];
        }

        $attempted = array_fill_keys(array_map('strval', $attemptedIds), true);
        $result = [];
        foreach($errors as $key=>$error)
        {
            if(is_array($error))
            {
                $recordId = '';
                foreach($identifierFields as $field)
                {
                    $recordId = trim((string)($error[$field] ?? ''));
                    if($recordId !== '')
                    {
                        break;
                    }
                }
                $message = trim((string)($error['error'] ?? $error['message'] ?? $defaultMessage));
            } elseif(is_string($key) && trim($key) !== '') {
                $recordId = trim($key);
                $message = trim((string)$error);
            } else {
                return ['*'=>'Wangdiantong batch response contained a malformed error record.'];
            }
            if($recordId === '' || !isset($attempted[$recordId]))
            {
                return ['*'=>'Wangdiantong batch response referenced an unexpected record.'];
            }
            $result[$recordId] = self::truncate(
                WangDianLogSanitizer::sanitizeText($message === '' ? $defaultMessage : $message),
                1000
            );
        }
        return $result;
    }

    public static function countMatches(
        array $response,
        array $attemptedIds,
        array $errors,
        bool $allowImplicitSingleSuccess = false
    ): bool
    {
        if(isset($errors['*']) && str_starts_with((string)$errors['*'], 'Wangdiantong batch response'))
        {
            return false;
        }
        if(!array_key_exists('new_count', $response) && !array_key_exists('chg_count', $response))
        {
            return !empty($errors) || ($allowImplicitSingleSuccess && count($attemptedIds) === 1);
        }
        $accepted = self::acceptedCount($response);
        if($accepted === null)
        {
            return false;
        }
        $expectedAccepted = count(array_filter($attemptedIds, static function($recordId) use ($errors): bool
        {
            return !isset($errors[(string)$recordId]) && !isset($errors['*']);
        }));
        return $accepted === $expectedAccepted;
    }

    private static function acceptedCount(array $response): ?int
    {
        $newCount = array_key_exists('new_count', $response)
            ? self::parseNonNegativeInteger($response['new_count'])
            : 0;
        $changedCount = array_key_exists('chg_count', $response)
            ? self::parseNonNegativeInteger($response['chg_count'])
            : 0;
        if($newCount === null || $changedCount === null || $newCount > PHP_INT_MAX-$changedCount)
        {
            return null;
        }
        return $newCount+$changedCount;
    }

    private static function parseNonNegativeInteger($value): ?int
    {
        if(is_int($value))
        {
            return $value >= 0 ? $value : null;
        }
        if(!is_string($value) || preg_match('/^(?:0|[1-9]\d*)$/D', $value) !== 1)
        {
            return null;
        }
        $max = (string)PHP_INT_MAX;
        if(strlen($value) > strlen($max) || (strlen($value) === strlen($max) && strcmp($value, $max) > 0))
        {
            return null;
        }
        return (int)$value;
    }

    private static function truncate(string $value, int $maxCharacters): string
    {
        if(preg_match('/^.{0,'.$maxCharacters.'}/us', $value, $matches) === 1)
        {
            return $matches[0];
        }
        return '';
    }
}
