<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\Tests;

use Rasuvaeff\PropertyTesting\ValueRenderer;
use Testo\Assert;
use Testo\Codecov\Covers;
use Testo\Data\DataProvider;
use Testo\Test;

#[Test]
#[Covers(ValueRenderer::class)]
final class ValueRendererTest
{
    #[DataProvider('scalarProvider')]
    public function rendersScalars(mixed $value, string $expected): void
    {
        Assert::same(ValueRenderer::render($value), $expected);
    }

    public static function scalarProvider(): iterable
    {
        yield 'null' => [null, 'null'];
        yield 'true' => [true, 'true'];
        yield 'false' => [false, 'false'];
        yield 'int' => [42, '42'];
        yield 'negative int' => [-7, '-7'];
        yield 'float' => [3.5, '3.5'];
        yield 'nan' => [fdiv(0.0, 0.0), 'NAN'];
        yield 'inf' => [INF, 'INF'];
        yield 'negative inf' => [-INF, '-INF'];
        yield 'negative zero' => [-0.0, '-0.0'];
        yield 'positive zero stays plain' => [0.0, '0'];
        yield 'short string' => ['abc', '"abc"'];
        yield 'empty list' => [[], '[]'];
    }

    public function rendersResourcesViaDebugType(): void
    {
        // A resource matches none of the scalar/array/object arms, so it exercises
        // the match's default (get_debug_type) arm.
        $resource = fopen('php://memory', 'r');
        \assert($resource !== false);

        try {
            Assert::same(ValueRenderer::render($resource), 'resource (stream)');
        } finally {
            fclose($resource);
        }
    }

    // --- strings ---------------------------------------------------------

    public function keepsAStringAtTheLengthLimitVerbatim(): void
    {
        // Exactly MAX_STRING (64) chars: no truncation (the boundary is `>`).
        $value = str_repeat('a', 64);

        Assert::same(ValueRenderer::render($value), '"' . $value . '"');
    }

    public function truncatesAStringOverTheLimitToExactlyTheLimit(): void
    {
        Assert::same(
            ValueRenderer::render(str_repeat('a', 100)),
            '"' . str_repeat('a', 64) . '…" (100 chars)',
        );
    }

    public function truncationKeepsThePrefixFromOffsetZero(): void
    {
        // A distinguishable first character pins the substring offset: keeping the
        // suffix (offset 1) or dropping it would change the visible prefix.
        Assert::same(
            ValueRenderer::render('X' . str_repeat('y', 100)),
            '"X' . str_repeat('y', 63) . '…" (101 chars)',
        );
    }

    public function countsCharactersNotBytesForMultibyteStrings(): void
    {
        // 40 two-byte chars = 80 bytes but only 40 characters — under the limit,
        // so no truncation (a byte-length check would wrongly truncate).
        $value = str_repeat('я', 40);

        Assert::same(ValueRenderer::render($value), '"' . $value . '"');
    }

    public function truncatesMultibyteStringsByCharacterExactly(): void
    {
        Assert::same(
            ValueRenderer::render(str_repeat('я', 70)),
            '"' . str_repeat('я', 64) . '…" (70 chars)',
        );
    }

    // --- binary strings --------------------------------------------------

    public function rendersShortBinaryStringsAsHexExactly(): void
    {
        Assert::same(ValueRenderer::render("\xff\x00\x41"), 'b"0xff0041" (3 byte(s))');
    }

    public function keepsBinaryAtTheLimitWithoutEllipsis(): void
    {
        // Exactly 64 bytes: full hex, no ellipsis (the boundary is `>`).
        $value = str_repeat("\xff", 64);

        Assert::same(ValueRenderer::render($value), 'b"0x' . str_repeat('ff', 64) . '" (64 byte(s))');
    }

    public function truncatesBinaryOverTheLimitToExactlyTheLimit(): void
    {
        $value = str_repeat("\xff", 65);

        Assert::same(ValueRenderer::render($value), 'b"0x' . str_repeat('ff', 64) . '…" (65 byte(s))');
    }

    // --- arrays ----------------------------------------------------------

    public function rendersListsAndMapsExactly(): void
    {
        Assert::same(ValueRenderer::render([1, 2, 3]), '[1, 2, 3]');
        Assert::same(ValueRenderer::render(['a' => 1, 'b' => 2]), '["a" => 1, "b" => 2]');
    }

    public function rendersIntegerMapKeysAsStrings(): void
    {
        // A non-sequential integer key makes this a map, not a list.
        Assert::same(ValueRenderer::render([5 => 'x']), '[5 => "x"]');
    }

    public function escapesAndBoundsStringMapKeys(): void
    {
        Assert::same(ValueRenderer::render(["a\n\"b" => 1]), '["a\n\"b" => 1]');
        Assert::same(
            ValueRenderer::render([str_repeat('x', 70) => 1]),
            '["' . str_repeat('x', 64) . '…" (70 chars) => 1]',
        );
    }

    public function keepsExactlyMaxElementsWithoutSummary(): void
    {
        Assert::same(ValueRenderer::render(range(1, 8)), '[1, 2, 3, 4, 5, 6, 7, 8]');
    }

    public function capsElementCountAndCountsTheRemainderExactly(): void
    {
        Assert::same(
            ValueRenderer::render(range(1, 12)),
            '[1, 2, 3, 4, 5, 6, 7, 8, … +4 more]',
        );
    }

    public function capsNestingDepthForLists(): void
    {
        Assert::same(
            ValueRenderer::render([[[[['deep']]]]]),
            '[[[[[… 1 element(s)]]]]]',
        );
    }

    public function capsNestingDepthForMaps(): void
    {
        Assert::same(
            ValueRenderer::render(['a' => ['b' => ['c' => ['d' => ['e' => 1]]]]]),
            '["a" => ["b" => ["c" => ["d" => [… 1 element(s)]]]]]',
        );
    }

    // --- objects ---------------------------------------------------------

    public function rendersEnumCases(): void
    {
        Assert::same(ValueRenderer::render(RenderColor::Red), RenderColor::class . '::Red');
    }

    public function rendersDateTimeInFullPrecision(): void
    {
        Assert::same(
            ValueRenderer::render(new \DateTimeImmutable('@0')),
            '1970-01-01 00:00:00.000000+00:00',
        );
    }

    public function rendersStringableVerbatimUnquoted(): void
    {
        $stringable = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return 'TRACE';
            }
        };

        Assert::same(ValueRenderer::render($stringable), 'TRACE');
    }

    public function escapesAndBoundsStringableValues(): void
    {
        $stringable = new class implements \Stringable {
            #[\Override]
            public function __toString(): string
            {
                return "trace\n" . str_repeat('x', 70);
            }
        };

        Assert::same(
            ValueRenderer::render($stringable),
            'trace\n' . str_repeat('x', 58) . '… (76 chars)',
        );
    }

    public function keepsAStringableAtTheLengthLimitVerbatim(): void
    {
        // Exactly MAX_STRING (64) chars: no truncation (the boundary is `>`).
        $value = str_repeat('x', 64);
        $stringable = new StringableTrace($value);

        Assert::same(ValueRenderer::render($stringable), $value);
    }

    public function countsStringableCharactersNotBytes(): void
    {
        // 40 two-byte chars = 80 bytes but only 40 characters — under the limit,
        // so no truncation (a byte-length check would wrongly truncate).
        $value = str_repeat('я', 40);

        Assert::same(ValueRenderer::render(new StringableTrace($value)), $value);
    }

    public function truncatesStringableMultibyteByCharacterExactly(): void
    {
        Assert::same(
            ValueRenderer::render(new StringableTrace(str_repeat('я', 70))),
            str_repeat('я', 64) . '… (70 chars)',
        );
    }

    public function rendersSimpleObjectProperties(): void
    {
        $object = new \stdClass();
        $object->x = 1;
        $object->label = 'hi';

        Assert::same(ValueRenderer::render($object), 'stdClass {x: 1, label: "hi"}');
    }

    public function rendersEmptyObject(): void
    {
        Assert::same(ValueRenderer::render(new \stdClass()), 'stdClass {}');
    }

    public function keepsExactlyMaxObjectPropertiesWithoutSummary(): void
    {
        $object = new \stdClass();
        for ($i = 0; $i < 8; ++$i) {
            $object->{'p' . $i} = $i;
        }

        Assert::same(
            ValueRenderer::render($object),
            'stdClass {p0: 0, p1: 1, p2: 2, p3: 3, p4: 4, p5: 5, p6: 6, p7: 7}',
        );
    }

    public function capsObjectPropertyCountAndCountsTheRemainder(): void
    {
        $object = new \stdClass();
        for ($i = 0; $i < 10; ++$i) {
            $object->{'p' . $i} = $i;
        }

        Assert::same(
            ValueRenderer::render($object),
            'stdClass {p0: 0, p1: 1, p2: 2, p3: 3, p4: 4, p5: 5, p6: 6, p7: 7, … +2 more}',
        );
    }

    public function capsNestingDepthForObjectAtTheFloor(): void
    {
        $object = new \stdClass();
        $object->x = 1;

        // The object sits at depth 0 (four array levels deep): its properties are
        // elided even though it is non-empty.
        Assert::same(ValueRenderer::render([[[[$object]]]]), '[[[[stdClass {…}]]]]');
    }

    public function capsNestingDepthThroughObjectProperties(): void
    {
        $leaf = new \stdClass();
        $leaf->x = 1;
        $node = $leaf;
        for ($i = 0; $i < 4; ++$i) {
            $wrapper = new \stdClass();
            $wrapper->x = $node;
            $node = $wrapper;
        }

        Assert::same(
            ValueRenderer::render($node),
            'stdClass {x: stdClass {x: stdClass {x: stdClass {x: stdClass {…}}}}}',
        );
    }

    public function guardsAgainstObjectCyclesShowingClassAndMarker(): void
    {
        $object = new \stdClass();
        $object->self = $object;

        Assert::same(ValueRenderer::render($object), 'stdClass {self: stdClass {*recursion*}}');
    }

    public function rendersPrivateAndPromotedDtoProperties(): void
    {
        // Real DTOs use private constructor-promoted properties, which
        // get_object_vars() would hide from outside the class.
        Assert::same(
            ValueRenderer::render(new RenderDto(7, 'x')),
            RenderDto::class . ' {id: 7, name: "x"}',
        );
    }

    public function rendersPrivatePropertiesFromTheWholeInheritanceChain(): void
    {
        Assert::same(
            ValueRenderer::render(new ChildRenderDto(2)),
            ChildRenderDto::class . ' {child: 2, base: 1}',
        );
    }

    public function disambiguatesShadowedPrivateProperties(): void
    {
        Assert::same(
            ValueRenderer::render(new ShadowingChildRenderDto()),
            ShadowingChildRenderDto::class . ' {ShadowingChildRenderDto::$id: 2, ShadowingBaseRenderDto::$id: 1}',
        );
    }

    public function escapesQuotesNewlinesAndControlCharacters(): void
    {
        // "a", newline, quote, "b", tab — must stay on one unambiguous line.
        Assert::same(ValueRenderer::render("a\n\"b\t"), '"a\n\"b\t"');
    }

    public function fallsBackToThePropertyDumpWhenToStringThrows(): void
    {
        // Diagnostics for an already-failing run must not be displaced by a
        // throwing __toString().
        Assert::same(
            ValueRenderer::render(new ThrowingStringableDto()),
            ThrowingStringableDto::class . ' {id: 1}',
        );
    }

    public function normalizeOmitsTheStringFormWhenToStringThrows(): void
    {
        Assert::same(ValueRenderer::normalize(new ThrowingStringableDto()), [
            '__type' => ThrowingStringableDto::class,
            'properties' => ['id' => 1],
        ]);
    }

    public function normalizesAClosedResourceToItsDebugType(): void
    {
        // is_resource() is false for a closed resource, but json_encode()
        // still cannot encode it — normalize() must not let it escape raw.
        $resource = fopen('php://memory', 'r');
        \assert($resource !== false);
        fclose($resource);

        Assert::same(ValueRenderer::normalize($resource), 'resource (closed)');
    }

    public function rendersEmptyListAtDepthFloorAsEmpty(): void
    {
        // An empty array at the depth floor is still "[]", not the depth-elision
        // marker (the empty-check precedes the depth check).
        Assert::same(ValueRenderer::render([[[[[]]]]]), '[[[[[]]]]]');
    }

    public function rendersEmptyObjectAtDepthFloorAsEmpty(): void
    {
        Assert::same(ValueRenderer::render([[[[new \stdClass()]]]]), '[[[[stdClass {}]]]]');
    }

    public function normalizesDomainObjectsForMachineReadableOutput(): void
    {
        $cycle = new \stdClass();
        $cycle->self = $cycle;
        $stringable = new class implements \Stringable {
            public int $id = 7;

            #[\Override]
            public function __toString(): string
            {
                return 'trace';
            }
        };

        Assert::same(ValueRenderer::normalize(RenderColor::Red), [
            '__type' => RenderColor::class,
            'case' => 'Red',
        ]);
        Assert::same(ValueRenderer::normalize(RenderStatus::Ready), [
            '__type' => RenderStatus::class,
            'case' => 'Ready',
            'value' => 'ready',
        ]);
        Assert::same(ValueRenderer::normalize(new \DateTimeImmutable('@0')), [
            '__type' => \DateTimeImmutable::class,
            'value' => '1970-01-01 00:00:00.000000+00:00',
        ]);
        Assert::same(ValueRenderer::normalize($cycle), [
            '__type' => \stdClass::class,
            'properties' => [
                'self' => ['__type' => \stdClass::class, '__recursion' => true],
            ],
        ]);
        Assert::same(ValueRenderer::normalize($stringable), [
            '__type' => $stringable::class,
            'properties' => ['id' => 7, '__string' => 'trace'],
        ]);
    }

    public function normalizesSpecialValuesAndBoundsMachineDepth(): void
    {
        $resource = fopen('php://memory', 'r');
        \assert($resource !== false);
        $deep = 1;
        $expected = ['__truncated' => true];
        for ($i = 0; $i < 32; ++$i) {
            $deep = [$deep];
            $expected = [$expected];
        }

        try {
            Assert::same(ValueRenderer::normalize(INF), 'INF');
            Assert::same(ValueRenderer::normalize($resource), 'resource (stream)');
            Assert::same(ValueRenderer::normalize($deep), $expected);
        } finally {
            fclose($resource);
        }
    }

    public function exportsRunnablePhpValues(): void
    {
        Assert::same(ValueRenderer::exportPhp(null), 'null');
        Assert::same(ValueRenderer::exportPhp(value: false), 'false');
        Assert::same(ValueRenderer::exportPhp(7), '7');
        Assert::same(ValueRenderer::exportPhp(NAN), 'NAN');
        Assert::same(ValueRenderer::exportPhp(INF), 'INF');
        Assert::same(ValueRenderer::exportPhp(-INF), '-INF');
        Assert::same(ValueRenderer::exportPhp(-0.0), '-0.0');
        Assert::same(ValueRenderer::exportPhp(0.0), '0.0');
        Assert::same(ValueRenderer::exportPhp(2.0), '2.0');
        Assert::same(ValueRenderer::exportPhp(2.5), '2.5');
        Assert::same(ValueRenderer::exportPhp(1.0E+100), '1.0E+100');
        Assert::same(ValueRenderer::exportPhp(-5.0E-324), '-5.0E-324');
        Assert::same(ValueRenderer::exportPhp(['x' => 1]), "['x' => 1]");
        Assert::same(ValueRenderer::exportPhp(RenderColor::Blue), '\\' . RenderColor::class . '::Blue');
    }

    public function refusesToExportNonRunnableTypesWithAnExactMessage(): void
    {
        try {
            ValueRenderer::exportPhp(new \stdClass());

            Assert::fail('expected a LogicException');
        } catch (\LogicException $e) {
            Assert::same($e->getMessage(), 'Cannot export stdClass as runnable PHP example code');
        }
    }

    public function boundsMachineDepthThroughObjectProperties(): void
    {
        // A chain of exactly 32 objects: the leaf sits at machine depth 0, so it
        // (and only it) is replaced by the truncation marker — one level less
        // truncates an object too early, one level more leaks the leaf.
        $node = 7;
        $expected = ['__truncated' => true];
        for ($i = 0; $i < 32; ++$i) {
            $wrapper = new \stdClass();
            $wrapper->next = $node;
            $node = $wrapper;
            $expected = ['__type' => \stdClass::class, 'properties' => ['next' => $expected]];
        }

        Assert::same(ValueRenderer::normalize($node), $expected);
    }
}

final readonly class StringableTrace implements \Stringable
{
    public function __construct(
        private string $value,
    ) {}

    #[\Override]
    public function __toString(): string
    {
        return $this->value;
    }
}

enum RenderColor
{
    case Red;
    case Blue;
}

enum RenderStatus: string
{
    case Ready = 'ready';
}

final class ThrowingStringableDto implements \Stringable
{
    public int $id = 1;

    #[\Override]
    public function __toString(): string
    {
        throw new \RuntimeException('boom');
    }
}

final class RenderDto
{
    // A static property must be excluded from the rendered instance state.
    private static int $rendered = 0;

    public function __construct(
        private readonly int $id,
        private readonly string $name,
    ) {
        ++self::$rendered;
    }

    public function getId(): int
    {
        return $this->id;
    }

    public function getName(): string
    {
        return $this->name;
    }
}

class BaseRenderDto
{
    public function __construct(
        private readonly int $base = 1,
    ) {}

    public function getBase(): int
    {
        return $this->base;
    }
}

final class ChildRenderDto extends BaseRenderDto
{
    public function __construct(
        private readonly int $child,
    ) {
        parent::__construct();
    }

    public function getChild(): int
    {
        return $this->child;
    }
}

class ShadowingBaseRenderDto
{
    private int $id = 1;

    public function getBaseId(): int
    {
        return $this->id;
    }
}

final class ShadowingChildRenderDto extends ShadowingBaseRenderDto
{
    private int $id = 2;

    public function getChildId(): int
    {
        return $this->id;
    }
}
