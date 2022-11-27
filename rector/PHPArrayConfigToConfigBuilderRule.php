<?php

namespace Rector\Symfony\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr\Array_;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Type\ClassStringType;
use PHPStan\Type\ObjectType;
use Rector\Core\Rector\AbstractRector;
use Rector\Core\Util\StringUtils;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;
use function Symfony\Component\String\u;

class PHPArrayConfigToConfigBuilderRule extends AbstractRector
{
    public function __construct(private SimpleCallableNodeTraverser $simpleCallableNodeTraverser)
    {
    }

    /**
     * @return array<class-string<Node>>
     */
    public function getNodeTypes(): array
    {
        // what node types are we looking for?
        // pick any node from https://github.com/rectorphp/php-parser-nodes-docs/
        return [Closure::class];
    }

    /**
     * @param Closure $node - we can add Closure type here, because only this node is in "getNodeTypes()"
     */
    public function refactor(Node $node): ?Node
    {
        $configClasses = [];

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($node, function(Node $node) use (&$configClasses) {
            if (! $node instanceof MethodCall) {
                return null;
            }

            if (! $this->isObjectType($node->var, new ObjectType(ContainerConfigurator::class))) {
                return null;
            }

            if (! $this->isName($node->name, 'extension')) {
                return null;
            }

            $configName = $node->args[0]->value->value;
            if (!$configName) {
                return null;
            }

            $configVariable = $configName . 'Config';
            $configClassName = 'Symfony\\Config\\' . ucfirst($configName) . 'Config';
            $configClasses[$configClassName] = $configVariable;

            /** @var Node\Expr\Array_ $configArray */
            $configArray = $node->args[1]->value;

            $stmts = $this->transformFluentItems(
                $configVariable,
                $configArray
            );

            return $stmts; // FIXME fails here: "System error: "enterNode() returned invalid value of type array"
        });

        $node->params = [];
        foreach ($configClasses as $configClass => $configVariable) {
            $node->params[] = $this->nodeFactory->createParamFromNameAndType($configVariable, new ObjectType($configClass));
        }

        return $node;
    }

    /**
     * @return Node\Expr[]
     */
    private function transformFluentItems(MethodCall|string $methodCallOrVariableName, Array_ $items): array
    {
        $stmts = [];

        $currentStmt = null;
        foreach ($items->items as $item) {
            $method = $this->underscoreToCamelCase($item->key->value);
            $value = $item->value;

            if ($item->value instanceof Array_) {
                // TODO array node does not automatically tell us that a fluent call is possible
                //      some key -> value like like twig globals need some handing here
                //      or example an array node like here: https://github.com/sulu/sulu/blob/7562cff8c54df40a81527b6ff717c22674a5c1b3/src/Sulu/Bundle/MediaBundle/DependencyInjection/Configuration.php#L92-L98
                $fluentStmts = $this->transformFluentItems(
                    $currentStmt ?? $this->nodeFactory->createMethodCall($methodCallOrVariableName, $method), $value
                );

                $currentStmt = array_pop($fluentStmts);

                foreach ($fluentStmts as $stmt) {
                    $fluentStmts[] = $stmt;
                }

                continue;
            }

            if ($currentStmt) {
                $stmts[] = $currentStmt;
            }

            $currentStmt = $this->nodeFactory->createMethodCall(
                $currentStmt ?? $methodCallOrVariableName,
                $method,
                [$value]
            );
        }

        if ($currentStmt) {
            $stmts[] = $currentStmt;
        }

        return $stmts;
    }

    private function underscoreToCamelCase(string $name): string
    {
        $uppercaseWords = \ucwords($name, '_');
        $pascalCaseName = \str_replace('_', '', $uppercaseWords);

        return \lcfirst($pascalCaseName);
    }

    /**
     * This method helps other to understand the rule and to generate documentation.
     */
    public function getRuleDefinition(): RuleDefinition
    {
        return new RuleDefinition(
            'Change method calls from set* to change*.', [
                new CodeSample(
                    <<<'PHP'
                        return static function (\Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator $containerConfigurator): void {
                            $containerConfigurator->extension('framework', [
                                'cache' => null,
                            ]);
                        };
                    PHP,
                    <<<'PHP'
                        return static function (\Symfony\Config\FrameworkConfig $frameworkConfig): void {
                            $frameworkConfig->cache([]);
                        };
                    PHP
                ),
            ]
        );
    }
}
