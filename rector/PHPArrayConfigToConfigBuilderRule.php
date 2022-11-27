<?php

namespace Rector\Symfony\Rector\ClassMethod;

use PhpParser\Node;
use PhpParser\Node\Expr\Closure;
use PhpParser\Node\Expr\MethodCall;
use PHPStan\Type\ClassStringType;
use PHPStan\Type\ObjectType;
use Rector\Core\Rector\AbstractRector;
use Rector\PhpDocParser\NodeTraverser\SimpleCallableNodeTraverser;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symplify\RuleDocGenerator\ValueObject\CodeSample\CodeSample;
use Symplify\RuleDocGenerator\ValueObject\RuleDefinition;

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

        $this->simpleCallableNodeTraverser->traverseNodesWithCallable($node, function(Node $node) use (&$configClasses): ?array {
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

            $stmts = $this->transformItems(
                $configVariable,
                $configArray->items
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
    private function transformItems($configVariable, array $items): array
    {
        $stmts = [];

        foreach ($items as $item) {
            $stmts[] = $this->nodeFactory->createMethodCall(
                $configVariable,
                $item->key->value,
                [$item->value]
            );
        }

        return $stmts;
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
