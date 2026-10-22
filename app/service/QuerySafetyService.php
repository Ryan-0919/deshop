<?php
// +----------------------------------------------------------------------
// | ShopXO query input safety helpers
// +----------------------------------------------------------------------
namespace app\service;

/**
 * Restrict dynamic query fragments to explicitly approved identifiers.
 */
class QuerySafetyService
{
    /**
     * Normalize a sort direction or return the supplied safe default.
     */
    public static function Direction($value, $default = '')
    {
        $direction = is_string($value) ? strtolower(trim($value)) : '';
        return in_array($direction, ['asc', 'desc'], true) ? $direction : $default;
    }

    /**
     * Validate a comma-separated field list against known database columns.
     */
    public static function FieldList($value, array $allowed, $default = '*')
    {
        if($value === '*')
        {
            return '*';
        }
        if(!is_string($value) || trim($value) === '')
        {
            return $default;
        }

        $allowed_map = self::AllowedMap($allowed);
        $result = [];
        foreach(explode(',', $value) as $field)
        {
            $key = strtolower(trim($field));
            if($key === '' || !array_key_exists($key, $allowed_map))
            {
                return $default;
            }
            $result[] = $allowed_map[$key];
        }
        return empty($result) ? $default : implode(',', $result);
    }

    /**
     * Validate a comma-separated ORDER BY expression.
     */
    public static function OrderBy($value, array $allowed, $default)
    {
        if(!is_string($value) || trim($value) === '')
        {
            return $default;
        }

        $allowed_map = self::AllowedMap($allowed);
        $result = [];
        foreach(explode(',', $value) as $clause)
        {
            if(preg_match('/\A([A-Za-z_][A-Za-z0-9_.]*)(?:\s+(asc|desc))?\z/iD', trim($clause), $matches) !== 1)
            {
                return $default;
            }
            $key = strtolower($matches[1]);
            if(!array_key_exists($key, $allowed_map))
            {
                return $default;
            }
            $direction = empty($matches[2]) ? '' : ' '.strtolower($matches[2]);
            $result[] = $allowed_map[$key].$direction;
        }
        return empty($result) ? $default : implode(',', $result);
    }

    /**
     * Lists use their values; associative maps may expose a safe public alias.
     */
    private static function AllowedMap(array $allowed)
    {
        $result = [];
        foreach($allowed as $key=>$value)
        {
            $public = is_int($key) ? $value : $key;
            if(is_string($public) && is_string($value))
            {
                $result[strtolower($public)] = $value;
            }
        }
        return $result;
    }
}
?>
