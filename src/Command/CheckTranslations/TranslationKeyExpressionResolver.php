<?php

namespace Efabrica\TranslationsAutomatization\Command\CheckTranslations;

use PhpParser\Node;
use PhpParser\Node\Expr;
use PhpParser\Node\Name;

class TranslationKeyExpressionResolver
{
    private int $combinationLimit;

    /** @var array<string, ExpressionEvaluationResult> */
    private array $classConstants = [];

    private ?ProjectClassIndex $classIndex = null;

    private ?string $currentClassName = null;

    public function __construct(int $combinationLimit = 25)
    {
        $this->combinationLimit = $combinationLimit;
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $classConstants
     */
    public function withClassConstants(array $classConstants): self
    {
        $clone = clone $this;
        $clone->classConstants = $classConstants;

        return $clone;
    }

    public function withClassContext(ProjectClassIndex $classIndex, ?string $currentClassName): self
    {
        $clone = clone $this;
        $clone->classIndex = $classIndex;
        $clone->currentClassName = $currentClassName;

        return $clone;
    }

    /**
     * Build a partial key pattern with '*' placeholders for the unresolvable parts.
     * Returns null if no useful literal information can be extracted.
     *
     * @param array<string, ExpressionEvaluationResult> $scope
     * @param array<string, Expr> $expressionScope original right-hand-side expressions of in-scope variables; used to recurse through variable assignments
     * @param array<string, true> $visitedVariables internal recursion guard against cyclic variable lookups
     */
    public function derivePattern(Expr $expression, array $scope = [], array $expressionScope = [], array $visitedVariables = []): ?string
    {
        $resolved = $this->resolve($expression, $scope);
        if ($resolved->isResolved() && count($resolved->getValues()) === 1) {
            return $resolved->getValues()[0];
        }

        if ($expression instanceof Node\Scalar\String_) {
            return $expression->value;
        }

        if ($expression instanceof Node\Scalar\Encapsed) {
            $pattern = '';
            foreach ($expression->parts as $part) {
                if ($part instanceof Node\Scalar\EncapsedStringPart) {
                    $pattern .= $part->value;
                } elseif ($part instanceof Expr) {
                    $sub = $this->derivePattern($part, $scope, $expressionScope, $visitedVariables);
                    $pattern .= ($sub !== null && $sub !== '') ? $sub : '*';
                } else {
                    $pattern .= '*';
                }
            }

            return $pattern;
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            $left = $this->derivePattern($expression->left, $scope, $expressionScope, $visitedVariables);
            $right = $this->derivePattern($expression->right, $scope, $expressionScope, $visitedVariables);
            if ($left === null || $right === null) {
                return null;
            }

            return $left . $right;
        }

        if ($expression instanceof Expr\FuncCall
            && $expression->name instanceof Name
            && strtolower($expression->name->toString()) === 'sprintf'
        ) {
            if (!isset($expression->args[0]) || !$expression->args[0] instanceof Node\Arg) {
                return null;
            }

            $format = $this->derivePattern($expression->args[0]->value, $scope, $expressionScope, $visitedVariables);
            if (!is_string($format)) {
                return null;
            }

            $argPatterns = [];
            foreach (array_slice($expression->args, 1) as $argument) {
                if (!$argument instanceof Node\Arg) {
                    $argPatterns[] = '*';
                    continue;
                }

                $argPattern = $this->derivePattern($argument->value, $scope, $expressionScope, $visitedVariables);
                $argPatterns[] = is_string($argPattern) && $argPattern !== '' ? $argPattern : '*';
            }

            return $this->substituteSprintfPlaceholders($format, $argPatterns);
        }

        if ($expression instanceof Expr\Ternary) {
            $ifBranch = $expression->if !== null ? $this->derivePattern($expression->if, $scope, $expressionScope, $visitedVariables) : $this->derivePattern($expression->cond, $scope, $expressionScope, $visitedVariables);
            $elseBranch = $this->derivePattern($expression->else, $scope, $expressionScope, $visitedVariables);
            if ($ifBranch === null && $elseBranch === null) {
                return null;
            }

            return $ifBranch ?? $elseBranch;
        }

        if ($expression instanceof Expr\Match_) {
            $armPatterns = [];
            foreach ($expression->arms as $arm) {
                if ($arm->body instanceof Expr\Throw_) {
                    continue;
                }
                $armPattern = $this->derivePattern($arm->body, $scope, $expressionScope, $visitedVariables);
                if ($armPattern === null) {
                    return '*';
                }
                $armPatterns[$armPattern] = true;
            }

            if ($armPatterns === []) {
                return null;
            }
            if (count($armPatterns) === 1) {
                return (string) array_key_first($armPatterns);
            }

            return '*';
        }

        if ($expression instanceof Expr\BinaryOp\Coalesce) {
            $left = $this->derivePattern($expression->left, $scope, $expressionScope, $visitedVariables);
            if ($left !== null) {
                return $left;
            }

            return $this->derivePattern($expression->right, $scope, $expressionScope, $visitedVariables);
        }

        if ($expression instanceof Expr\ClassConstFetch) {
            $constResult = $this->resolveClassConstFetch($expression);
            if ($constResult->isResolved() && count($constResult->getValues()) === 1) {
                return $constResult->getValues()[0];
            }

            return '*';
        }

        if ($expression instanceof Expr\ConstFetch) {
            $name = strtolower($expression->name->toString());
            if ($name === 'null' || $name === 'false') {
                return '';
            }
            if ($name === 'true') {
                return '1';
            }

            return '*';
        }

        if ($expression instanceof Expr\Variable
            && is_string($expression->name)
            && isset($expressionScope[$expression->name])
            && !isset($visitedVariables[$expression->name])
        ) {
            $visitedVariables[$expression->name] = true;
            $nested = $this->derivePattern($expressionScope[$expression->name], $scope, $expressionScope, $visitedVariables);
            if (is_string($nested) && $nested !== '') {
                return $nested;
            }

            return '*';
        }

        if ($expression instanceof Expr\Variable
            || $expression instanceof Expr\PropertyFetch
            || $expression instanceof Expr\NullsafePropertyFetch
            || $expression instanceof Expr\ArrayDimFetch
            || $expression instanceof Expr\MethodCall
            || $expression instanceof Expr\NullsafeMethodCall
            || $expression instanceof Expr\StaticCall
            || $expression instanceof Expr\FuncCall
            || $expression instanceof Expr\New_
        ) {
            return '*';
        }

        return null;
    }

    /**
     * @param string[] $argPatterns
     */
    private function substituteSprintfPlaceholders(string $format, array $argPatterns): string
    {
        $index = 0;

        return (string) preg_replace_callback(
            '/%%|%(?:(\d+)\$)?[+-]?(?:\d+|\*)?(?:\.\d+)?[bcdeEfFgGhHosuxX]/',
            function (array $match) use ($argPatterns, &$index): string {
                if ($match[0] === '%%') {
                    return '%';
                }

                if (isset($match[1]) && $match[1] !== '') {
                    $argIndex = (int) $match[1] - 1;
                } else {
                    $argIndex = $index++;
                }

                $value = $argPatterns[$argIndex] ?? '*';

                return $value !== '' ? $value : '*';
            },
            $format
        );
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    public function resolve(Expr $expression, array $scope = []): ExpressionEvaluationResult
    {
        if ($expression instanceof Node\Scalar\String_) {
            return ExpressionEvaluationResult::resolved([$expression->value], false, ['string_literal']);
        }

        if ($expression instanceof Expr\Variable && is_string($expression->name)) {
            if (!isset($scope[$expression->name])) {
                return ExpressionEvaluationResult::unresolved(true, ['unknown_variable'], ['$' . $expression->name]);
            }

            return $scope[$expression->name]->asDynamic()
                ->withStrategy('variable_lookup')
                ->withVariable('$' . $expression->name);
        }

        if ($expression instanceof Expr\ConstFetch) {
            return $this->resolveConstFetch($expression);
        }

        if ($expression instanceof Expr\ClassConstFetch) {
            return $this->resolveClassConstFetch($expression);
        }

        if ($expression instanceof Expr\Array_) {
            return $this->resolveArrayExpression($expression, $scope);
        }

        if ($expression instanceof Expr\ArrayDimFetch) {
            return $this->resolveArrayDimFetch($expression, $scope);
        }

        if ($expression instanceof Expr\PropertyFetch) {
            return $this->resolvePropertyFetch($expression, $scope);
        }

        if ($expression instanceof Expr\BinaryOp\Concat) {
            return $this->combine(
                $this->resolve($expression->left, $scope),
                $this->resolve($expression->right, $scope),
                true
            );
        }

        if ($expression instanceof Node\Scalar\Encapsed) {
            $result = ExpressionEvaluationResult::resolved(['']);
            foreach ($expression->parts as $part) {
                if ($part instanceof Node\Scalar\EncapsedStringPart) {
                    $partResult = ExpressionEvaluationResult::resolved([$part->value], true, ['string_interpolation']);
                } elseif ($part instanceof Expr) {
                    $partResult = $this->resolve($part, $scope)->asDynamic();
                } else {
                    return ExpressionEvaluationResult::unresolved(true, ['string_interpolation']);
                }

                $result = $this->combine($result, $partResult, true);
                if (!$result->isResolved()) {
                    return $result;
                }
            }

            return $result->asDynamic()->withStrategy('string_interpolation');
        }

        if ($expression instanceof Expr\Ternary) {
            $if = $expression->if !== null ? $this->resolve($expression->if, $scope) : $this->resolve($expression->cond, $scope);
            $else = $this->resolve($expression->else, $scope);

            return $this->union([$if, $else], true);
        }

        if ($expression instanceof Expr\Match_) {
            $branchResults = [];
            foreach ($expression->arms as $arm) {
                if ($arm->body instanceof Expr\Throw_) {
                    continue;
                }
                $branchResults[] = $this->resolve($arm->body, $scope)->asDynamic();
            }
            if ($branchResults === []) {
                return ExpressionEvaluationResult::unresolved(true, ['match_expression']);
            }

            return $this->union($branchResults, true)->withStrategy('match_expression');
        }

        if ($expression instanceof Expr\BinaryOp\Coalesce) {
            return $this->union([
                $this->resolve($expression->left, $scope),
                $this->resolve($expression->right, $scope),
            ], true);
        }

        if ($expression instanceof Expr\FuncCall) {
            return $this->resolveFunctionCall($expression, $scope);
        }

        if ($expression instanceof Expr\New_) {
            return $this->resolveNewExpression($expression, $scope);
        }

        if ($expression instanceof Expr\MethodCall) {
            return $this->resolveMethodCall($expression, $scope);
        }

        if ($expression instanceof Expr\StaticCall) {
            return $this->resolveStaticCall($expression, $scope);
        }

        return ExpressionEvaluationResult::unresolved(true, ['unsupported_expression']);
    }

    private function resolveConstFetch(Expr\ConstFetch $expression): ExpressionEvaluationResult
    {
        $name = strtolower($expression->name->toString());
        if ($name === 'null') {
            return ExpressionEvaluationResult::resolved([''], true, ['null_constant']);
        }
        if ($name === 'true') {
            return ExpressionEvaluationResult::resolved(['1'], true, ['bool_constant']);
        }
        if ($name === 'false') {
            return ExpressionEvaluationResult::resolved([''], true, ['bool_constant']);
        }

        return ExpressionEvaluationResult::unresolved(true, ['unknown_constant:' . $name]);
    }

    private function resolveClassConstFetch(Expr\ClassConstFetch $expression): ExpressionEvaluationResult
    {
        if (!$expression->name instanceof Node\Identifier) {
            return ExpressionEvaluationResult::unresolved(true, ['unknown_class_constant']);
        }

        $constantName = $expression->name->toString();
        $resolvedClassName = $this->resolveClassConstOwner($expression);
        if ($resolvedClassName !== null && $this->classIndex !== null) {
            $constantValue = $this->classIndex->findConstantValue($resolvedClassName, $constantName);
            if ($constantValue !== null) {
                return $constantValue
                    ->asDynamic()
                    ->withStrategy('class_constant_fetch')
                    ->withVariable($resolvedClassName . '::' . $constantName);
            }
        }

        if (!isset($this->classConstants[$constantName])) {
            return ExpressionEvaluationResult::unresolved(true, ['unknown_class_constant:' . $constantName]);
        }

        return $this->classConstants[$constantName]
            ->asDynamic()
            ->withStrategy('class_constant_fetch')
            ->withVariable('self::' . $constantName);
    }

    private function resolveClassConstOwner(Expr\ClassConstFetch $expression): ?string
    {
        if (!$expression->class instanceof Name) {
            return $this->currentClassName;
        }

        $className = $expression->class->toString();
        $normalizedClassName = strtolower($className);
        if (in_array($normalizedClassName, ['self', 'static'], true)) {
            return $this->currentClassName;
        }

        if ($normalizedClassName === 'parent') {
            if ($this->classIndex === null || $this->currentClassName === null) {
                return null;
            }

            return $this->classIndex->findParentClassName($this->currentClassName);
        }

        return $className;
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveArrayExpression(Expr\Array_ $expression, array $scope): ExpressionEvaluationResult
    {
        if ($expression->items === null) {
            return ExpressionEvaluationResult::resolvedArray([], [], true, ['array_literal']);
        }

        $values = [];
        $arrayItems = [];
        $strategies = ['array_literal'];
        $variablesUsed = [];
        $nextIndex = 0;
        foreach ($expression->items as $item) {
            if ($item === null) {
                continue;
            }

            $itemResult = $this->resolve($item->value, $scope);
            if (!$itemResult->isResolved()) {
                return ExpressionEvaluationResult::unresolved(
                    true,
                    array_merge($strategies, $itemResult->getStrategies()),
                    array_merge($variablesUsed, $itemResult->getVariablesUsed())
                );
            }

            $values = array_merge($values, $itemResult->getValues());
            $strategies = array_merge($strategies, $itemResult->getStrategies());
            $variablesUsed = array_merge($variablesUsed, $itemResult->getVariablesUsed());
            $resolvedKey = $this->resolveArrayItemKey($item->key, $scope, $nextIndex);
            if ($resolvedKey !== null) {
                $arrayItems[$resolvedKey] = $itemResult;
            }

            $nextIndex++;
            if (count($values) > $this->combinationLimit) {
                return ExpressionEvaluationResult::unresolved(
                    true,
                    array_merge($strategies, ['combination_limit_exceeded']),
                    $variablesUsed
                );
            }
        }

        return ExpressionEvaluationResult::resolvedArray($values, $arrayItems, true, $strategies, $variablesUsed);
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveArrayDimFetch(Expr\ArrayDimFetch $expression, array $scope): ExpressionEvaluationResult
    {
        if ($expression->dim === null) {
            return ExpressionEvaluationResult::unresolved(true, ['array_dim_fetch']);
        }

        $arrayResult = $this->resolve($expression->var, $scope);
        $keyResult = $this->resolveArrayDimKey($expression->dim, $scope);
        if ($keyResult === null) {
            return ExpressionEvaluationResult::unresolved(
                true,
                array_merge($arrayResult->getStrategies(), ['array_dim_fetch']),
                $arrayResult->getVariablesUsed()
            );
        }

        if (!$arrayResult->isResolved() || !$arrayResult->hasArrayItems()) {
            return ExpressionEvaluationResult::unresolved(
                true,
                array_merge($arrayResult->getStrategies(), $keyResult->getStrategies(), ['array_dim_fetch']),
                array_merge($arrayResult->getVariablesUsed(), $keyResult->getVariablesUsed())
            );
        }

        if (count($keyResult->getValues()) !== 1) {
            return ExpressionEvaluationResult::unresolved(
                true,
                array_merge($arrayResult->getStrategies(), $keyResult->getStrategies(), ['array_dim_fetch']),
                array_merge($arrayResult->getVariablesUsed(), $keyResult->getVariablesUsed())
            );
        }

        $itemResult = $arrayResult->getArrayItem((string) $keyResult->getValues()[0]);
        if ($itemResult === null) {
            return ExpressionEvaluationResult::unresolved(
                true,
                array_merge($arrayResult->getStrategies(), $keyResult->getStrategies(), ['array_dim_fetch', 'unknown_array_key']),
                array_merge($arrayResult->getVariablesUsed(), $keyResult->getVariablesUsed())
            );
        }

        return $itemResult
            ->asDynamic()
            ->withStrategy('array_dim_fetch');
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolvePropertyFetch(Expr\PropertyFetch $expression, array $scope): ExpressionEvaluationResult
    {
        if (!$expression->name instanceof Node\Identifier) {
            return ExpressionEvaluationResult::unresolved(true, ['property_fetch']);
        }

        $objectResult = $this->resolve($expression->var, $scope);
        if (!$objectResult->hasObjectProperties()) {
            return ExpressionEvaluationResult::unresolved(
                true,
                array_merge($objectResult->getStrategies(), ['property_fetch']),
                $objectResult->getVariablesUsed()
            );
        }

        $propertyResult = $objectResult->getObjectProperty($expression->name->toString());
        if ($propertyResult === null) {
            return ExpressionEvaluationResult::unresolved(
                true,
                array_merge($objectResult->getStrategies(), ['property_fetch', 'unknown_property']),
                $objectResult->getVariablesUsed()
            );
        }

        return $propertyResult
            ->asDynamic()
            ->withStrategy('property_fetch');
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveFunctionCall(Expr\FuncCall $expression, array $scope): ExpressionEvaluationResult
    {
        if (!$expression->name instanceof Name) {
            return ExpressionEvaluationResult::unresolved(true, ['unknown_function']);
        }

        $functionName = strtolower($expression->name->toString());
        if ($functionName !== 'sprintf') {
            return ExpressionEvaluationResult::unresolved(true, ['unsupported_function:' . $functionName]);
        }

        $arguments = $expression->args;
        if ($arguments === [] || !isset($arguments[0])) {
            return ExpressionEvaluationResult::unresolved(true, ['sprintf']);
        }

        $format = $this->resolve($arguments[0]->value, $scope);
        if (!$format->isResolved() || count($format->getValues()) !== 1) {
            return ExpressionEvaluationResult::unresolved(true, ['sprintf'], $format->getVariablesUsed());
        }

        $resolvedArguments = [];
        foreach (array_slice($arguments, 1) as $argument) {
            $resolvedArgument = $this->resolve($argument->value, $scope);
            if (!$resolvedArgument->isResolved() || count($resolvedArgument->getValues()) !== 1) {
                return ExpressionEvaluationResult::unresolved(true, ['sprintf'], $resolvedArgument->getVariablesUsed());
            }

            $resolvedArguments[] = $resolvedArgument->getValues()[0];
        }

        $formatted = @sprintf($format->getValues()[0], ...$resolvedArguments);
        if (!is_string($formatted)) {
            return ExpressionEvaluationResult::unresolved(true, ['sprintf']);
        }

        return ExpressionEvaluationResult::resolved([$formatted], true, ['sprintf'], $format->getVariablesUsed());
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveNewExpression(Expr\New_ $expression, array $scope): ExpressionEvaluationResult
    {
        if (!$expression->class instanceof Name) {
            return ExpressionEvaluationResult::unresolved(true, ['new_object']);
        }

        $className = $expression->class->toString();
        $constructor = $this->classIndex !== null ? $this->classIndex->findMethod($className, '__construct') : null;
        $parameterNames = $constructor !== null ? $constructor->parameterNames : [];

        $objectProperties = [];
        foreach ($expression->args as $index => $argument) {
            if (!$argument instanceof Node\Arg) {
                continue;
            }

            $propertyName = $argument->name !== null
                ? $argument->name->toString()
                : ($parameterNames[$index] ?? null);
            if (!is_string($propertyName) || $propertyName === '') {
                continue;
            }

            $objectProperties[$propertyName] = $this->resolve($argument->value, $scope);
        }

        if ($objectProperties === []) {
            return ExpressionEvaluationResult::unresolved(true, ['new_object']);
        }

        $variablesUsed = [];
        foreach ($objectProperties as $propertyResult) {
            $variablesUsed = array_merge($variablesUsed, $propertyResult->getVariablesUsed());
        }

        return ExpressionEvaluationResult::resolvedObject(
            $objectProperties,
            true,
            ['new_object'],
            array_values(array_unique($variablesUsed))
        );
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveMethodCall(Expr\MethodCall $expression, array $scope): ExpressionEvaluationResult
    {
        if (!$expression->name instanceof Node\Identifier) {
            return ExpressionEvaluationResult::unresolved(true, ['unknown_method_call']);
        }

        $methodName = strtolower($expression->name->toString());
        if ($methodName !== 'translate') {
            return ExpressionEvaluationResult::unresolved(true, ['unsupported_method_call:' . $methodName]);
        }

        return $this->resolveTranslateLikeArguments($expression->args, $scope, 'nested_' . $methodName . '_method_call');
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveStaticCall(Expr\StaticCall $expression, array $scope): ExpressionEvaluationResult
    {
        if (!$expression->name instanceof Node\Identifier) {
            return ExpressionEvaluationResult::unresolved(true, ['unknown_static_call']);
        }

        $methodName = strtolower($expression->name->toString());
        if ($methodName !== 'translate') {
            return ExpressionEvaluationResult::unresolved(true, ['unsupported_static_call:' . $methodName]);
        }

        return $this->resolveTranslateLikeArguments($expression->args, $scope, 'nested_' . $methodName . '_static_call');
    }

    /**
     * @param array<int, Node\Arg> $arguments
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveTranslateLikeArguments(array $arguments, array $scope, string $strategy): ExpressionEvaluationResult
    {
        if (!isset($arguments[0])) {
            return ExpressionEvaluationResult::unresolved(true, [$strategy]);
        }

        return $this->resolve($arguments[0]->value, $scope)->asDynamic()->withStrategy($strategy);
    }

    private function combine(ExpressionEvaluationResult $left, ExpressionEvaluationResult $right, bool $dynamic): ExpressionEvaluationResult
    {
        if (!$left->isResolved() || !$right->isResolved()) {
            return ExpressionEvaluationResult::unresolved(
                $dynamic,
                array_merge($left->getStrategies(), $right->getStrategies(), ['concat']),
                array_merge($left->getVariablesUsed(), $right->getVariablesUsed())
            );
        }

        $combined = [];
        foreach ($left->getValues() as $leftValue) {
            foreach ($right->getValues() as $rightValue) {
                $combined[] = $leftValue . $rightValue;
                if (count($combined) > $this->combinationLimit) {
                    return ExpressionEvaluationResult::unresolved(
                        $dynamic,
                        array_merge($left->getStrategies(), $right->getStrategies(), ['concat', 'combination_limit_exceeded']),
                        array_merge($left->getVariablesUsed(), $right->getVariablesUsed())
                    );
                }
            }
        }

        return ExpressionEvaluationResult::resolved(
            $combined,
            $dynamic || $left->isDynamic() || $right->isDynamic(),
            array_merge($left->getStrategies(), $right->getStrategies(), ['concat']),
            array_merge($left->getVariablesUsed(), $right->getVariablesUsed())
        );
    }

    /**
     * @param ExpressionEvaluationResult[] $results
     */
    private function union(array $results, bool $dynamic): ExpressionEvaluationResult
    {
        $values = [];
        $strategies = $this->mergeStrategies($results, ['branch_union']);
        $variablesUsed = $this->mergeVariablesUsed($results);
        foreach ($results as $result) {
            if (!$result->isResolved()) {
                return ExpressionEvaluationResult::unresolved(
                    $dynamic,
                    $strategies,
                    $variablesUsed
                );
            }

            foreach ($result->getValues() as $value) {
                $values[] = $value;
                if (count($values) > $this->combinationLimit) {
                    return ExpressionEvaluationResult::unresolved(
                        $dynamic,
                        array_merge($strategies, ['combination_limit_exceeded']),
                        $variablesUsed
                    );
                }
            }
        }

        return ExpressionEvaluationResult::resolved(
            $values,
            $dynamic,
            $strategies,
            $variablesUsed
        );
    }

    /**
     * @param ExpressionEvaluationResult[] $results
     * @param string[] $append
     * @return string[]
     */
    private function mergeStrategies(array $results, array $append = []): array
    {
        $strategies = [];
        foreach ($results as $result) {
            $strategies = array_merge($strategies, $result->getStrategies());
        }

        return array_values(array_unique(array_merge($strategies, $append)));
    }

    /**
     * @param ExpressionEvaluationResult[] $results
     * @return string[]
     */
    private function mergeVariablesUsed(array $results): array
    {
        $variablesUsed = [];
        foreach ($results as $result) {
            $variablesUsed = array_merge($variablesUsed, $result->getVariablesUsed());
        }

        return array_values(array_unique($variablesUsed));
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveArrayDimKey(Expr $expression, array $scope): ?ExpressionEvaluationResult
    {
        if ($expression instanceof Node\Scalar\String_) {
            return ExpressionEvaluationResult::resolved([$expression->value], true, ['array_key_literal']);
        }

        if ($expression instanceof Node\Scalar\Int_) {
            return ExpressionEvaluationResult::resolved([(string) $expression->value], true, ['array_key_literal']);
        }

        $result = $this->resolve($expression, $scope);

        return $result->isResolved() ? $result : null;
    }

    /**
     * @param array<string, ExpressionEvaluationResult> $scope
     */
    private function resolveArrayItemKey(?Expr $expression, array $scope, int $fallbackIndex): ?string
    {
        if ($expression === null) {
            return (string) $fallbackIndex;
        }

        if ($expression instanceof Node\Scalar\String_) {
            return $expression->value;
        }

        if ($expression instanceof Node\Scalar\Int_) {
            return (string) $expression->value;
        }

        $result = $this->resolveArrayDimKey($expression, $scope);
        if ($result === null || count($result->getValues()) !== 1) {
            return null;
        }

        return (string) $result->getValues()[0];
    }
}
