<?php

declare(strict_types=1);

namespace Rasuvaeff\PropertyTesting\StateMachine;

/**
 * Marks a public method of a rule-based machine as one step the generated
 * sequence may take. Its parameters are drawn the way a property's are —
 * from an override, the docblock, then the native type
 * ({@see \Rasuvaeff\PropertyTesting\Gen::forParameters()}); overrides come
 * from a `public static function <rule>Generators(): array` on the machine,
 * or from the method $generators names.
 *
 * The body is the step: it drives the system under test, updates the model
 * the machine keeps as its own state, and asserts — an exception is the
 * failed postcondition. See {@see \Rasuvaeff\PropertyTesting\Gen::rules()}.
 *
 * @api
 */
#[\Attribute(\Attribute::TARGET_METHOD)]
final readonly class Rule
{
    /**
     * @param ?string $generators A public static method of the machine returning generators by
     *        parameter name, for the parameters a type cannot describe; null looks for
     *        `<rule>Generators()` and derives the rest from the signature either way.
     */
    public function __construct(
        public ?string $generators = null,
    ) {}
}
