<?php
/**
 *
 * Custom CSS functions
 *
 */
namespace CssCrush;

class Functions
{
    protected static $builtins = array(

        // These functions must come first in this order.
        'query' => 'CssCrush\fn__query',

        // These functions can be any order.
        'math' => 'CssCrush\fn__math',
        'hsla-adjust' => 'CssCrush\fn__hsla_adjust',
        'hsl-adjust' => 'CssCrush\fn__hsl_adjust',
        'h-adjust' => 'CssCrush\fn__h_adjust',
        's-adjust' => 'CssCrush\fn__s_adjust',
        'l-adjust' => 'CssCrush\fn__l_adjust',
        'a-adjust' => 'CssCrush\fn__a_adjust',
    );

    /** @var array */
    public $register = [];

    protected $pattern;

    protected $patternOptions;

    public function __construct($register = [])
    {
        $this->register = $register;
    }

    public function add($name, $callback)
    {
        $this->register[$name] = $callback;
    }

    public function remove($name)
    {
        unset($this->register[$name]);
    }

    public function setPattern($useAll = false)
    {
        if ($useAll) {
            $this->register = self::$builtins + $this->register;
        }

        $this->pattern = Functions::makePattern(array_keys($this->register));
    }

    public function apply($str, \stdClass $context = null)
    {
        if (strpos($str, '(') === false) {
            return $str;
        }

        if (! $this->pattern) {
            $this->setPattern();
        }

        if (! preg_match($this->pattern, $str)) {
            return $str;
        }

        $matches = Regex::matchAll($this->pattern, $str);

        while ($match = array_pop($matches)) {

            if (isset($match['function']) && $match['function'][1] !== -1) {
                list($function, $offset) = $match['function'];
            }
            else {
                list($function, $offset) = $match['simple_function'];
            }

            if (! preg_match(Regex::$patt->parens, $str, $parens, PREG_OFFSET_CAPTURE, $offset)) {
                continue;
            }

            $openingParen = $parens[0][1];
            $closingParen = $openingParen + strlen($parens[0][0]);
            $rawArgs = trim($parens['parens_content'][0]);

            // Update the context function identifier.
            if ($context) {
                $context->function = $function;
            }

            $returns = '';
            if (isset($this->register[$function])) {
                $fn = $this->register[$function];
                if (is_array($fn) && !empty($fn['parse_args'])) {
                    $returns = $fn['callback'](self::parseArgs($rawArgs), $context);
                }
                else {
                    $returns = $fn($rawArgs, $context);
                }
            }

            $str = substr_replace($str, $returns, $offset, $closingParen - $offset);
        }

        return $str;
    }


    #############################
    #  API and helpers.

    public static function parseArgs($input, $allowSpaceDelim = false)
    {
        $options = [];
        if ($allowSpaceDelim) {
            $options['regex'] = Regex::$patt->argListSplit;
        }

        return Util::splitDelimList($input, $options);
    }

    /*
        Quick argument list parsing for functions that take 1 or 2 arguments
        with the proviso the first argument is an ident.
    */
    public static function parseArgsSimple($input)
    {
        $args = preg_split(Regex::$patt->argListSplit, $input, 2);
        if (!isset($args[1])) {
            $args[1] = null;
        }
        return $args;
    }

    public static function makePattern($functionNames)
    {
        $idents = [];
        $nonIdents = [];

        foreach ($functionNames as $functionName) {
            if (preg_match(Regex::$patt->ident, $functionName[0])) {
                $idents[] = preg_quote($functionName);
            }
            else {
                $nonIdents[] = preg_quote($functionName);
            }
        }

        if ($idents) {
            $idents = '{{ LB }}-?(?<function>' . implode('|', $idents) . ')';
        }
        if ($nonIdents) {
            $nonIdents = '(?<simple_function>' . implode('|', $nonIdents) . ')';
        }

        if ($idents && $nonIdents) {
            $patt = "(?:$idents|$nonIdents)";
        }
        elseif ($idents) {
            $patt = $idents;
        }
        elseif ($nonIdents) {
            $patt = $nonIdents;
        }

        return Regex::make("~$patt\(~iS");
    }
}


#############################
#  Stock CSS functions.

function fn__math($input) {

    list($expression, $unit) = array_pad(Functions::parseArgs($input), 2, '');

    // Swap in math constants.
    $expression = preg_replace(
        array('~\bpi\b~i'),
        array(M_PI),
        $expression);

    // If no unit is specified scan expression.
    if (! $unit) {
        $numPatt = Regex::$classes->number;
        if (preg_match("~\b{$numPatt}(?<unit>[A-Za-z]{2,4}\b|%)~", $expression, $m)) {
            $unit = $m['unit'];
        }
    }

    // Filter expression so it's just characters necessary for simple math.
    $expression = preg_replace("~[^.0-9/*()+-]~S", '', $expression);

    $evalExpression = "return $expression;";
    $result = false;

    if (class_exists('\\ParseError')) {
        try {
            $result = @eval($evalExpression);
        }
        catch (\Error $e) {}
    }
    else {
        $result = @eval($evalExpression);
    }

    return ($result === false ? 0 : round($result, 5)) . $unit;
}

/**
 * Manipulate the hue, saturation, lightness and opacity of a color value.
 * @param string $input
 * @return string The modified color value.
 */
function fn__hsla_adjust($input)
{
    list($color, $h, $s, $l, $a) = array_pad(Functions::parseArgs($input, true), 5, 0);
    return fn__color_adjust($color, $h, $s, $l, $a);
}

/**
 * Manipulate the hue, saturation, and lightness of a color value.
 * @param string $input
 * @return string The modified color value.
 */
function fn__hsl_adjust($input)
{
    list($color, $h, $s, $l) = array_pad(Functions::parseArgs($input, true), 4, 0);
    return fn__color_adjust($color, $h, $s, $l, 0);
}

/**
 * Adjust the hue of a color value.
 * @param string $input
 * @return string The modified color value.
 */
function fn__h_adjust($input)
{
    list($color, $h) = array_pad(Functions::parseArgs($input, true), 2, 0);
    return fn__color_adjust($color, $h, 0, 0, 0);
}

/**
 * Adjust the saturation of a color value.
 * @param string $input
 * @return string The modified color value.
 */
function fn__s_adjust($input)
{
    list($color, $s) = array_pad(Functions::parseArgs($input, true), 2, 0);
    return fn__color_adjust($color, 0, $s, 0, 0);
}

/**
 * Adjust the lightness of a color value.
 * @param string $input
 * @return string The modified color value.
 */
function fn__l_adjust($input)
{
    list($color, $l) = array_pad(Functions::parseArgs($input, true), 2, 0);
    return fn__color_adjust($color, 0, 0, $l, 0);
}

/**
 * Manipulate the opacity (alpha channel) of a color value.
 * @param $input
 * @return string The modified color value.
 */
function fn__a_adjust($input)
{
    list($color, $a) = array_pad(Functions::parseArgs($input, true), 2, 0);
    return fn__color_adjust($color, 0, 0, 0, $a);
}

/**
 * Manipulate the hue, saturation, lightness and opacity of a color value.
 *
 * Emits two events:
 * - 'color_adjust_before' allow plugins to intercept color prior to color test and adjustment.
 * - 'color_adjust_after' allow plugins to intercept color after adjustment.
 *
 * @param string $color Any valid CSS color value
 * @param string|float $h The percentage to offset the color hue (percent mark optional)
 * @param string|float $s The percentage to offset the color saturation (percent mark optional)
 * @param string|float $l The percentage to offset the color lightness (percent mark optional)
 * @param string|float $a The percentage to offset the color opacity (percent mark optional)
 * @return string The modified color value.
 */
function fn__color_adjust($color, $h, $s, $l, $a) {
    Crush::$process->emit('color_adjust_before', array(
        'color' => &$color,
        'h' => &$h,
        's' => &$s,
        'l' => &$l,
        'a' => &$a
    ));
    $adjustedColor = Color::test($color) ? Color::colorAdjust($color, array($h, $s, $l, $a)) : '';
    Crush::$process->emit('color_adjust_after', array(
        'color' => &$adjustedColor,
        'h' => $h,
        's' => $s,
        'l' => $l,
        'a' => $a
    ));
    return $adjustedColor;
}

function fn__this($input, $context) {

    $args = Functions::parseArgsSimple($input);
    $property = $args[0];

    // Function relies on a context rule, bail if none.
    if (! isset($context->rule)) {
        return '';
    }
    $rule = $context->rule;

    $rule->declarations->expandData('data', $property);

    if (isset($rule->declarations->data[$property])) {

        return $rule->declarations->data[$property];
    }

    // Fallback value.
    elseif (isset($args[1])) {

        return $args[1];
    }

    return '';
}

function fn__query($input, $context) {

    $args = Functions::parseArgs($input);

    // Context property is required.
    if (! count($args) || ! isset($context->property)) {
        return '';
    }

    list($target, $property, $fallback) = $args + array(null, $context->property, null);

    if (strtolower($property) === 'default') {
        $property = $context->property;
    }

    if (! preg_match(Regex::$patt->rooted_ident, $target)) {
        $target = Selector::makeReadable($target);
    }

    $targetRule = null;
    $references =& Crush::$process->references;

    switch (strtolower($target)) {
        case 'parent':
            $targetRule = $context->rule->parent;
            break;
        case 'previous':
            $targetRule = $context->rule->previous;
            break;
        case 'next':
            $targetRule = $context->rule->next;
            break;
        case 'top':
            $targetRule = $context->rule->parent;
            while ($targetRule && $targetRule->parent && $targetRule = $targetRule->parent);
            break;
        default:
            if (isset($references[$target])) {
                $targetRule = $references[$target];
            }
            break;
    }

    $result = '';
    if ($targetRule) {
        $targetRule->declarations->process();
        $targetRule->declarations->expandData('queryData', $property);
        if (isset($targetRule->declarations->queryData[$property])) {
            $result = $targetRule->declarations->queryData[$property];
        }
    }

    if ($result === '' && isset($fallback)) {
        $result = $fallback;
    }

    return $result;
}
