<?php
/**
 * @link https://craftcms.com/
 * @copyright Copyright (c) Pixel & Tonic, Inc.
 * @license https://craftcms.github.io/license/
 */

namespace craft\gql\base;

use Craft;
use craft\base\Field;
use craft\base\EagerLoadingFieldInterface;
use craft\base\GqlInlineFragmentFieldInterface;
use craft\helpers\StringHelper;
use GraphQL\Language\AST\FieldNode;
use GraphQL\Language\AST\FragmentSpreadNode;
use GraphQL\Language\AST\InlineFragmentNode;
use GraphQL\Language\AST\Node;
use GraphQL\Type\Definition\ResolveInfo;

/**
 * Class Resolver
 *
 * @author Pixel & Tonic, Inc. <support@pixelandtonic.com>
 * @since 3.3.0
 */
abstract class Resolver
{
    /**
     * Cache fields by context.
     *
     * @var array
     */
    protected static $eagerLoadableFieldsByContext;

    /**
     * Resolve a field to its value.
     *
     * @param mixed $source The parent data source to use for resolving this field
     * @param array $arguments arguments for resolving this field.
     * @param mixed $context The context shared between all resolvers
     * @param ResolveInfo $resolveInfo The resolve information
     */
    abstract public static function resolve($source, array $arguments, $context, ResolveInfo $resolveInfo);

    /**
     * Returns a list of all the arguments that can be accepted as arrays.
     *
     * @return array
     */
    public static function getArrayableArguments(): array
    {
        return [];
    }

    /**
     * Prepare arguments for use, converting to array where applicable.
     *
     * @param array $arguments
     * @return array
     */
    public static function prepareArguments(array $arguments): array
    {
        $arrayable = static::getArrayableArguments();

        foreach ($arguments as $key => &$value) {
            if (in_array($key, $arrayable, true) && !empty($value) && !is_array($value)) {
                $array = StringHelper::split($value);

                if (count($array) > 1) {
                    $value = $array;
                }
            }
        }

        return $arguments;
    }

    /**
     * Extract eager load conditions for a given resolve information. Preferrably at the very top of the query.
     *
     * @param Node $parentNode
     * @return array
     */
    protected static function extractEagerLoadCondition(ResolveInfo $resolveInfo) {
        $startingNode = $resolveInfo->fieldNodes[0];
        $fragments = $resolveInfo->fragments;

        if ($startingNode === null) {
            return [];
        }

        /**
         * Traverse child nodes of a GraphQL query formed as AST.
         *
         * This method traverses all the child descendant nodes recursively for a GraphQL query AST node,
         * keeping track of where in the tree it currently resides to correctly build the `with` clause
         * for the resulting element query.
         *
         * @param Node $parentNode the parent node being traversed.
         * @param string $prefix the current eager loading prefix to use
         * @param string $context the context in which to search fields
         * @param null $parentField the current parent field, that we are in.
         * @return array
         */
        $traverseNodes = function (Node $parentNode, $prefix = '', $context = 'global', $parentField = null) use (&$traverseNodes, $fragments)
        {
            $eagerLoadNodes = [];
            $subNodes = $parentNode->selectionSet->selections ?? [];

            // For each subnode that is a direct descendant
            foreach ($subNodes as $subNode) {
                $nodeName = $subNode->name->value ?? null;

                // If that's a GraphQL field
                if ($subNode instanceof FieldNode) {

                    // That is a Craft field that can be eager-loaded
                    if ($field = static::getPreloadableField($subNode->name->value, $context)) {
                        $arguments = [];

                        // Any arguments?
                        $argumentNodes = $subNode->arguments ?? [];
                        foreach ($argumentNodes as $argumentNode) {
                            if (isset($argumentNode->value->values)) {
                                $values = [];

                                foreach ($argumentNode->value->values as $value) {
                                    $values[] = $value->value;
                                }

                                $arguments[$argumentNode->name->value] = $values;
                            } else {
                                $arguments[$argumentNode->name->value] = $argumentNode->value->value;
                            }
                        }
                        }

                        // Add it all to the list
                        $eagerLoadNodes[$prefix.$nodeName] = $arguments;

                        // If it has any more selections, build the prefix further and proceed in a recursive manner
                        if (!empty($subNode->selectionSet)) {
                            $eagerLoadNodes += $traverseNodes($subNode, $prefix . $field->handle . '.', $field->context, $field);
                        }
                    }
                // If not, see if it's an inline fragment
                } else if ($subNode instanceof InlineFragmentNode) {
                    $nodeName = $subNode->typeCondition->name->value;

                    // If we are inside a field that supports different subtypes, it should implement the appropriate interface
                    if ($parentField instanceof GqlInlineFragmentFieldInterface) {
                        // Get the Craft entity that correlates to the fragment
                        // Build the prefix, load the context and proceed in a recursive manner
                        $gqlFragmentEntity = $parentField->getGqlFragmentEntityByName($nodeName);
                        $eagerLoadNodes += $traverseNodes($subNode, $prefix . $gqlFragmentEntity->getEagerLoadingPrefix() . ':', $gqlFragmentEntity->getFieldContext(), $parentField);
                    // If we are not, just expand the fragment and traverse it as if on the same level in the query tree
                    } else {
                        $eagerLoadNodes += $traverseNodes($subNode, $prefix, $context, $parentField);
                    }
                // Finally, if this is a named fragment, expand it and traverse it as if on the same level in the query tree
                } else if ($subNode instanceof FragmentSpreadNode) {
                    $fragmentDefinition = $fragments[$nodeName];
                    $eagerLoadNodes += $traverseNodes($fragmentDefinition, $prefix, $context, $parentField);
                }
            }

            return $eagerLoadNodes;
        };

        return $traverseNodes($startingNode);
    }

    /**
     * Get the preloadable field for the context or null if the field doesn't exist or is not preloadable.
     *
     * @param array $subFields
     * @param string $context
     * @return Field|null
     */
    protected static function getPreloadableField($fieldHandle, $context = 'global')
    {
        if (static::$eagerLoadableFieldsByContext === null) {
            self::_loadEagerLoadableFields();
        }

        return self::$eagerLoadableFieldsByContext[$context][$fieldHandle] ?? null;
    }

    // Private methods
    // =========================================================================

    /**
     * Load all the fields
     */
    private static function _loadEagerLoadableFields()
    {
        $allFields = Craft::$app->getFields()->getAllFields(false);
        self::$eagerLoadableFieldsByContext = [];

        /** @var Field $field */
        foreach ($allFields as $field) {
            if ($field instanceof EagerLoadingFieldInterface) {
                self::$eagerLoadableFieldsByContext[$field->context][$field->handle] = $field;
            }
        }
    }
}
