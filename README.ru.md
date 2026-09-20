# rasuvaeff/property-testing-core

[![Latest Stable Version](https://poser.pugx.org/rasuvaeff/property-testing-core/v)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![Total Downloads](https://poser.pugx.org/rasuvaeff/property-testing-core/downloads)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![Build](https://github.com/rasuvaeff/property-testing-core/actions/workflows/build.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/build.yml)
[![Static analysis](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml/badge.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml)
[![Psalm level](https://img.shields.io/badge/psalm-level_1-blue.svg)](https://github.com/rasuvaeff/property-testing-core/actions/workflows/static-analysis.yml)
[![PHP](https://img.shields.io/packagist/dependency-v/rasuvaeff/property-testing-core/php)](https://packagist.org/packages/rasuvaeff/property-testing-core)
[![License](https://img.shields.io/badge/license-BSD--3--Clause-blue.svg)](LICENSE.md)

[English version](README.md)

Framework-agnostic **движок** property-based тестирования для PHP 8.3+:
генераторы с интегрированным shrinking, структурный runner, регрессионный
корпус, события жизненного цикла и stateful/model-based тестирование — без
зависимости от какого-либо тестового фреймворка. Сотни случайных входов на
проверку, поиск падающего и сжатие его до минимального контрпримера, который
реально читается.

> Работаете с AI-ассистентом? [llms.txt](llms.txt) содержит компактный API-справочник для модели. Если проект использует [`llm/skills`](https://github.com/roxblnfk/skills), скилл [`rasuvaeff-property-testing-core`](resources/skills/rasuvaeff-property-testing-core/SKILL.md) автоматически синхронизируется в `.agents/skills/` при `composer require` — он построен вокруг решений (какой генератор, какой механизм фазы выбрать), а полный синтаксис оставляет здесь. Чтобы зеркало скилла появилось в `.claude/skills/` или `.cursor/skills/` (один набор файлов, на уровне ОС — junction/symlink), добавьте `skills.json` в корень проекта: `{"target": ".agents/skills", "aliases": [".claude/skills", ".cursor/skills"]}` — либо запустите `composer skills:init` для интерактивного мастера.

## Семейство property-testing

| Пакет | Когда использовать |
|---|---|
| **`rasuvaeff/property-testing-core`** (этот пакет) | Вы управляете движком сами: собственный harness, CI-страж, CLI-проверка или адаптер другого фреймворка |
| [`rasuvaeff/property-testing-testo`](https://github.com/rasuvaeff/property-testing-testo) | Вы тестируете с [Testo](https://github.com/php-testo/testo) — drop-in замена замороженного `rasuvaeff/property-testing` с тем же атрибутом `#[Property]` |
| [`rasuvaeff/property-testing-phpunit`](https://github.com/rasuvaeff/property-testing-phpunit) | Вы тестируете с PHPUnit — trait `PropertyTesting` с fluent-API `forAll()->check()` |
| [`rasuvaeff/property-testing-openapi`](https://github.com/rasuvaeff/property-testing-openapi) | Есть OpenAPI-документ и нужны property-based contract-тесты для API за ним: сгенерированные valid/negative запросы, suite операций и pre-transport оракулы валидности поверх [`rasuvaeff/openapi-contract`](https://github.com/rasuvaeff/openapi-contract) |
| [`rasuvaeff/property-testing-names`](https://github.com/rasuvaeff/property-testing-names) | На входе люди: формы, профили, авторизация, валидаторы, отчёты — `Names::first()`/`last()`/`middle()` берут отдельные части независимо, `full()`/`person()` держат все части согласованными по одному полу; встроенные наборы `en` и `ru`, shrink к самой короткой записи |

> **Внимание:** пакет объявляет `conflict` с замороженным
> `rasuvaeff/property-testing` (2.x) — оба поставляют классы в namespace
> `Rasuvaeff\PropertyTesting`, поэтому Composer откажется ставить их вместе.
> Мигрируете с 2.x? Замените dev-зависимость на адаптер вашего фреймворка;
> импорты в коде не меняются. Полное руководство —
> [MIGRATION.md](MIGRATION.md) (на английском): две composer-команды и ни
> одной правки PHP для проектов на Testo плюс пути для своего harness и
> PHPUnit.

## Требования

- PHP 8.3+
- `ext-mbstring`
- `ext-random`
- `ext-tokenizer`

## Установка

```bash
composer require --dev rasuvaeff/property-testing-core
```

У движка нет зависимости от тестового фреймворка: вы передаёте ему определение
property и executor, он возвращает структурный результат. Он никогда не читает
переменные окружения, не печатает, не вызывает exit и не бросает исключение,
чтобы сообщить исход property.

## Использование

Соберите `PropertyDefinition` (генераторы по именам параметров плюс
`PropertyConfig`), выполните тело через `TrialExecutor`
(`CallableTrialExecutor` адаптирует обычное замыкание) и разберите
`PropertyResult`, который вернёт `PropertyRunner`:

```php
use Rasuvaeff\PropertyTesting\Gen;
use Rasuvaeff\PropertyTesting\Runner\CallableTrialExecutor;
use Rasuvaeff\PropertyTesting\Runner\Falsified;
use Rasuvaeff\PropertyTesting\Runner\PropertyConfig;
use Rasuvaeff\PropertyTesting\Runner\PropertyDefinition;
use Rasuvaeff\PropertyTesting\Runner\PropertyRunner;

$definition = new PropertyDefinition(
    id: 'demo::everyIntStaysBelowHundred',
    name: 'everyIntStaysBelowHundred',
    generators: ['value' => Gen::intBetween(0, 10_000)],
    parameterNames: ['value'],
    config: new PropertyConfig(runs: 200, seed: 42),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor(
    static function (int $value): void {
        if ($value >= 100) {
            throw new RuntimeException(sprintf('%d is not below 100', $value));
        }
    },
));

if ($result instanceof Falsified) {
    $example = $result->counterExample();
    // $example->seed, $example->originalArguments, $example->shrunkArguments, ...
    fwrite(STDERR, $result->failure()->getMessage());
}
```

Сообщение об ошибке рендерит контрпример:

```text
Property falsified after 0 successful run(s); seed=42
  Original: value=54
  Shrunk:   value=100 (3 shrink step(s), 11 trial(s))
  Changed:  value=54 -> 100
  Failure:  100 is not below 100
```

Строка `Changed:` показывает разницу между исходным и сжатым контрпримером —
аргументы, которые shrinker не тронул, опущены. `trial(s)` — все кандидаты
shrinker'а (принятые и отвергнутые); `shrink step(s)` — только принятые.
Кандидат принимается, только если падает так же, как исходный прогон — с тем
же классом исключения: спуск минимизирует найденный баг, а не сползает в
другой (меньший вход, роняющий `TypeError` в подготовке тела, — не меньший
контрпример для assertion-падения). Точное воспроизведение прогона — запиньте
seed из отчёта в `PropertyConfig`.

Полный исполняемый скрипт —
[`examples/standalone_runner.php`](examples/standalone_runner.php).

### Шов executor

`TrialExecutor` — граница между движком и тем, что исполняет тело property.
Каждый вызов `execute($arguments)` возвращает `TrialOutcome` — `passed()`,
`failed($throwable)`, `discarded()` или `skipped()`:

- `CallableTrialExecutor` — standalone-executor: нормальный возврат — pass,
  `Assume::that()` — discard, любой другой throwable — failure.
- Адаптеры фреймворков реализуют свои (Testo мапит `TestResult`, PHPUnit —
  assertion-исключения) — цикл run/shrink никогда не узнаёт о типах
  фреймворка.

`Assume::that()` сигнализирует discard исключением `AssumptionSkipped`, и оно —
часть этого шва: executor ловит его и возвращает `TrialOutcome::discarded()`,
ровно так делают `CallableTrialExecutor` и оба адаптера. Больше ничто в движке
его не бросает, и оно не является `PropertyTestingException` (см. ниже): тело,
ловящее маркер, не должно проглатывать собственные discard'ы.

`discarded()` и `skipped()` оба значат «этот прогон ничего не проверил» и
считаются одинаково везде, кроме одного места. Discard — утверждение о
**входе**: он вышел из домена property, поэтому записанная регрессия, которая
на реплее даёт discard, уже никогда не упадёт и вычищается из корпуса. Skip —
утверждение об **окружении** (`markTestSkipped()` вокруг отсутствующей
зависимости, framework-skip из lifecycle-хука) и о входе не говорит ничего,
поэтому такая запись сохраняется. Они также тратят разные бюджеты и считаются
порознь в `RunStatistics`: skip'ы, списанные с бюджета discard'ов, заставляли
машину без зависимости сдаться с советом сузить генераторы, которые ни в чём
не виноваты. Экологический skip возвращайте как `skipped()` — иначе одна
машина без зависимости удалит контрпример для всех машин, где он
воспроизводится.

### Структурные результаты

`PropertyRunner::run()` возвращает один из закрытой иерархии `PropertyResult` —
невозможные комбинации данных непредставимы, а каждый непроходной исход несёт
собственный тип исключения движка с устоявшимся форматом сообщения:

| Результат | Значение | Несёт |
|---|---|---|
| `Passed` | Все проверки завершились, все coverage-требования выполнены | `RunStatistics` |
| `Falsified` | Случайный прогон упал; контрпример сжат | `PropertyViolationException` → `CounterExample` |
| `GaveUp` | Исчерпан бюджет discard'ов — или бюджет skip'ов — до `runs` проверок; какой именно, говорит `GaveUpException::$exhaustedBySkips`, и сообщение советует соответственно | `GaveUpException`, `RunStatistics` |
| `CoverageFailed` | Все прогоны прошли, но требование `Classify::cover()` не выполнено | `CoverageViolationException`, `RunStatistics` |
| `DeadlineExceeded` | Один прогон превысил `timeoutMs` | `DeadlineExceededException` |
| `TimeBudgetExceeded` | Случайная фаза превысила `budgetMs` | `TimeBudgetExceededException`, `RunStatistics` |
| `GenerationFailed` | Генератор не смог произвести валидное значение | `GenerationExhaustedException` |
| `ExampleFailed` | Упал явный example (examples идут первыми, без shrink) | `ExampleViolationException` |
| `RegressionFailed` | Записанная corpus-запись всё ещё падает | `RegressionViolationException` |
| `PathFailed` | Прогон фальсифицировал property, но не смог пройти закреплённый `path` | `PathViolationException` |

Ошибки конфигурации (`runs < 1`, отсутствующий генератор, несовпадение имён
параметров) остаются исключениями — это ошибки программиста, а не вердикт о
property.

Каждое исключение, о котором сообщает движок, — десять из таблицы результатов
выше плюс `GenerationExhaustedException` и
`StateMachine\PostconditionViolationException`, когда они всплывают из тела, —
реализует пустой маркер `PropertyTestingException` (`\Throwable`), так что
harness может написать `catch (PropertyTestingException $e)` для «всего, что
сказал этот пакет», не перечисляя типы. Каждое по-прежнему расширяет
`\RuntimeException`.

`RunStatistics` отдаёт сырые счётчики фазы (attempts, discards, skips, checks,
классификация по меткам, таблицы `tabulate()`, размер перебранного домена или
причину отказа от перебора, `SearchReport`) — distribution-отчёт и
предупреждение о discard'ах печатает адаптер; движок никогда не форматирует
вывод фреймворка.

Сериализация: каждый результат переживает нативный `serialize()`, когда trace
не захватывает значения аргументов (`zend.exception_ignore_args=1`);
переносимый машинный формат — `CounterExample::toArray()` / `toJson()`. Его
ключи заморожены (политика совместимости, п. 4): `seed`, `runsBeforeFailure`,
`originalArguments`, `shrunkArguments`, `shrinkSteps`, `shrinkTrials`, `path`,
`failure` (`{type, message}` или null), `discards`, `skips`, `edgeCases`,
`originalNotes`, `shrunkNotes`, `replays`, `passedOnReplay`. Как и
ключи `DistributionReport::toArray()`: `attempts`, `discards`,
`discardPercent`, `skips`, `checks`, `coverageAssessed`, `labels` (каждый —
`{label, count, percent, required, met}`), плюс `tables`, когда прогон
табулировал, и `exhaustive`, когда он просил перебор. Ключ может быть
добавлен в конец минором; переименование или удаление — только мажор.

### Генераторы

Все фабрики живут на фасаде `Gen`; каждая возвращает реализацию
`ArbitraryInterface`, чей `generate(Random)` отдаёт `Shrinkable` — значение
плюс ленивое дерево меньших кандидатов, поэтому преобразованные генераторы
сжимаются через исходный домен.

| Фабрика | Производит | Сжимается |
|---|---|---|
| `Gen::int()` | `IntArbitrary`, `PHP_INT_MIN..PHP_INT_MAX` | к `0` |
| `Gen::intBetween($min, $max)` | `IntArbitrary`, `[$min, $max]` | к `0`, в пределах диапазона |
| `Gen::intPositive()` | `IntArbitrary`, `1..PHP_INT_MAX` | к `1` |
| `Gen::float()` | `FloatArbitrary`, `[0.0, 1.0)` | к `0.0` |
| `Gen::floatBetween($min, $max)` | `FloatArbitrary`, `[$min, $max)` — сам `$max` никогда не выпадает; `floatBetween($x, $x)` — единственное значение `$x` | к точке `[$min, $max)`, ближайшей к `0.0`: `0.0`, если диапазон её содержит, `$min` выше нуля, наибольший float под `$max` на нуле и ниже — никогда не `$max` |
| `Gen::bool()` | `BoolArbitrary`, `true` / `false` | `true` -> `false` |
| `Gen::string()` | `StringArbitrary`, Unicode, длина 0..100 — половина символов ASCII printable, десятая часть «трудные» символы (кавычки, backslash, combining-знаки, zero-width joiner, right-to-left override, BOM, астральные эмодзи, …), десятая часть Latin-1/Latin Extended, десятая — остальной BMP, пятая — равномерно по U+0001..U+10FFFF | к `''`, затем удалением блоков символов с любой позиции (вплоть до одиночных), затем каждый символ к `a` |
| `Gen::stringAscii()` | `StringArbitrary`, печатный ASCII, длина 0..100 | к `''`, затем по длине, затем каждый символ к `a` |
| `Gen::stringOf($minLength, $maxLength)` | `StringArbitrary`, Unicode, ограниченная длина (`0..100` по умолчанию, как у `stringFrom` и `bytes`) | к `''`, затем по длине, затем каждый символ к `a` |
| `Gen::stringFrom($alphabet, $minLength, $maxLength)` | `CharsetStringArbitrary`, символы из фиксированного алфавита (multibyte OK) | к `''`, затем по длине, затем каждый символ к первому символу алфавита |
| `Gen::bytes($minLength, $maxLength)` | `BytesArbitrary`, сырые байтовые строки (байты 0..255) | к `''`, затем по длине, затем каждый байт к `"\x00"` |
| `Gen::arrayOf($element, $minSize, $maxSize)` | `ArrayArbitrary`, списки из `$element`, размер 0..100 по умолчанию | к `[]`, затем удалением блоков элементов с любой позиции (вплоть до одиночных), затем каждый элемент |
| `Gen::nonEmptyArrayOf($element, $maxSize)` | `ArrayArbitrary`, непустые списки | по длине (не ниже 1), затем каждый элемент |
| `Gen::uniqueArrayOf($element, $minSize, $maxSize, $by)` | `UniqueArrayArbitrary`, списки попарно различных элементов — строгое `===` по значениям (поэтому `NAN` никогда не равен себе и может встретиться несколько раз, а объекты различны, если это не один экземпляр) либо, с `by: fn ($v) => $v->id`, по ключу `int\|string`, который возвращает замыкание; ключ любого другого типа отвергается при генерации | как `arrayOf`, но кандидаты, совпадающие с другим элементом (или ключом), пропускаются |
| `Gen::subset($values, $minSize, $maxSize)` | `SubsetArbitrary`, подмножества фиксированного упорядоченного множества — различные члены `$values` в исходном порядке; дубликаты в источнике отвергаются | сначала размер (к пустому множеству), затем каждый элемент к более ранним позициям источника — минимальное подмножество — короткий префикс |
| `Gen::dictOf($key, $value, $minSize, $maxSize)` | `DictionaryArbitrary`, map'ы с различными ключами из `$key` (int/string) и значениями из `$value`, размер 0..100 по умолчанию; строковый ключ, который PHP хранит как целый (`"0"`, `"12"`, `"-3"`), перевыбирается как коллизия, чтобы map оставался `array<string, T>` — генератор ключей, дающий только такие строки, выдаёт `[]` (или бросает при `$minSize > 0`) | к `[]`, затем по размеру, затем каждое значение (ключи фиксированы) |
| `Gen::record($shape)` | `RecordArbitrary`, map фиксированной формы `['field' => $arb, ...]` | каждое поле через свой arbitrary, набор ключей фиксирован |
| `Gen::elements($array)` | `OneOfArbitrary`, одно значение из массива (массивная форма `oneOf`, генераторы отвергает так же) | к раньше перечисленным различным значениям |
| `Gen::enum(SomeEnum::class)` | `OneOfArbitrary` по case'ам enum'а | к раньше объявленным case'ам (объявляйте простые первыми) |
| `Gen::constant($value)` | `ConstantArbitrary`, всегда `$value` | не сжимается |
| `Gen::withEdgeCases($inner, ...$edgeCases)` | `EdgeCasedArbitrary`, `$inner` с авторскими граничными значениями: один draw из пяти — одно из них; остаётся включённым под `EdgeCases::None` и не меняет последовательность `$inner` для seed | сначала через граничные значения в порядке перечисления (предпочтительный минимум — первым), затем внутреннее дерево; граничное значение сжимается к перечисленным до него |
| `Gen::composite($body)` | `CompositeArbitrary`, значение, которое тело строит из зависимых draw через `Draw` — см. [Композитные генераторы](#композитные-генераторы-gencomposite) | по draw, ранние первыми: один draw заменяется кандидатом своего дерева, последующие берутся заново через новый диапазон |
| `Gen::randomEngine()` / `Gen::randomizer()` | `RandomEngineArbitrary`, `Random\Engine` (или `Random\Randomizer` над ним), чья случайность идёт через ленту draw — см. [Сжимаемая случайность](#сжимаемая-случайность-genrandomengine) | лента: каждый вызов движка — `draw#N` из восьми байт, сжимающихся к `"\0"` || `Gen::char()` | `StringArbitrary`, один печатный ASCII-символ | к `a` |
| `Gen::uuid()` | `UuidArbitrary`, RFC 4122 v4 UUID-строки | не сжимается |
| `Gen::datetime($min, $max)` | `DateTimeArbitrary`, UTC `DateTimeImmutable` с микросекундной точностью, в `[$min, $max]` (дробные части границ сохраняются) | к Unix-эпохе через целочисленную лестницу, в пределах диапазона |
| `Gen::floatSpecial()` | `OneOfArbitrary` по `NAN`, `±INF`, `-0.0` и краям представления float | к раньше перечисленным special-значениям |
| `Gen::intRange($min, $max)` | `FlatMappedArbitrary`, упорядоченные пары `[lo, hi]` с `lo <= hi` | обе границы сжимаются, порядок всегда сохраняется |
| `Gen::recursive($leaf, $wrap, $maxDepth)` | ограниченные рекурсивные структуры: `$wrap` поднимает arbitrary предыдущего уровня | внутри породившей значение ветви |
| `Gen::oneOf(...$values)` | `OneOfArbitrary`, одно из перечисленных значений — именно значений: генератор среди них отвергается (выбор между генераторами — `frequency()`) | к раньше перечисленным различным значениям (простые — первыми) |
| `Gen::nullable($inner)` | `NullableArbitrary`, `null` или значение `$inner` | предпочитает `null`, затем внутреннее дерево |
| `Gen::map($inner, $map)` | `MappedArbitrary`, `$inner`, преобразованный `$map` | через внутреннее дерево, с повторным применением `$map` |
| `Gen::flatMap($inner, $flatMap)` | `FlatMappedArbitrary`, зависимый генератор из `$flatMap($innerValue)` | сначала исходное значение (зависимое регенерируется), затем зависимое дерево |
| `Gen::filter($inner, $predicate)` | `FilteredArbitrary`, значения `$inner`, удовлетворяющие `$predicate` (после 100 отвергнутых draw бросает `GenerationExhaustedException` — никогда не отдаёт значение вне домена) | внутреннее дерево с отсечением кандидатов, не проходящих предикат |
| `Gen::tuple(...$elements)` | `TupleArbitrary`, кортеж фиксированной арности | каждая позиция через свой элемент, арность фиксирована |
| `Gen::frequency($pairs)` | `FrequencyArbitrary`, взвешенный выбор по парам `[вес, arbitrary]` | внутри породившей значение ветви |
| `Gen::ipv4()` | IPv4-строки в точечной нотации | каждый октет к `0` |
| `Gen::ipv6()` | IPv6-адреса в канонической текстовой форме RFC 5952 (нижний регистр, без ведущих нулей, самый длинный прогон нулевых групп сжат в `::`) | каждая группа к `0`, в пределе `::` |
| `Gen::email()` | адреса `local@label.tld` | к кратчайшим local/label и первому TLD |
| `Gen::url()` | URL `http(s)://host.tld[/path]` | к `http://a.com` |
| `Gen::json($maxDepth)` | JSON-кодируемое значение (null/bool/int/float/string/list/object) | внутри порождённой структуры |
| `Gen::jsonString($maxDepth)` | `json_encode`-текст `Gen::json()` | через дерево значения |
| `Gen::regex($pattern)` / `Gen::stringMatching($pattern)` | строки, соответствующие подмножеству regex (компилируется в комбинаторы), паттерн — **без разделителей**: `Gen::regex('[a-z]{3,6}')`, а не `'/[a-z]{3,6}/'`; `.` и отрицающий класс берут символы из printable ASCII (`0x20`..`0x7E`, без перевода строки) | более короткие/простые совпадения (через скомпилированные деревья) |
| `Gen::commands($initialModel, $commandGenerators, $minLength, $maxLength)` | `CommandSequenceArbitrary`, валидные последовательности команд для stateful-тестирования | сбрасывает блоки команд, затем упрощает каждую |
| `Gen::rules($machine, $minLength, $maxLength)` | `RuleSequence` над классом rule-машины (методы `#[Rule]`/`#[Precondition]`/`#[Invariant]`) — см. [Rule-машины](#rule-машины-genrules) | как `commands`: сбрасывает шаги, затем упрощает аргументы каждого || `Gen::swarm($arbitrary)` | `SwarmArbitrary`, swarm-тестирование: каждый случай видит лишь непустое подмножество вариантов обёрнутого генератора выбора (`oneOf`, `elements`, `frequency`, `commands`) | внутри подмножества, из которого случай получился, — обратно до полного алфавита не расширяется |
| `Gen::forClass($class, $overrides)` | `ClassArbitrary`, экземпляры по тому, что объявляет конструктор: psalm-тип из докблока, если он есть (`int<0, 100>`, `non-empty-string`, `list<LineItem>`, `Status\|null`, `'a'\|'b'`; имена классов резолвятся через namespace и `use`-импорты файла), иначе нативный. Читаются три написания: `@psalm-param`/`@phpstan-param` берут верх над `@param`, а `@var` (`@psalm-var`) на самом promoted-свойстве читается, когда докблок конструктора о нём молчит. Нативный `float` означает `floatBetween(-1e6, 1e6)`. Всё, что прочитать нельзя — голый `array`, `mixed`, нативный union, докблок-тип вне подмножества, неизвестное имя класса (называется в сообщении), — исключение, а не догадка, как и override с именем несуществующего параметра | через сгенерированные аргументы, пересобирая экземпляр |
| `Gen::forParameters($function, $overrides)` | не arbitrary, а карта: `array<string, ArbitraryInterface>` для параметров `ReflectionFunctionAbstract` (метода или кложуры), по именам в порядке сигнатуры — правила `forClass`, применённые к любой сигнатуре; overrides могут быть частичными, остальное достраивается; всё нечитаемое — исключение с именем функции и параметра | каждая запись shrink'ается через свой генератор |

Числовые генераторы (`int*`, `float*`) **boundary-biased**: примерно каждый
пятый draw возвращает краевое значение диапазона (`0`, `±1`, `min`, `max` для
int; `0.0` или `min` для float), где кучкуются баги, вместо равномерного.
Shrinking это не затрагивает. Когда края — это ровно то, чего property
не может использовать (тело отбрасывает `0`, конец диапазона нарушает
предусловие), `edgeCases: EdgeCases::None` генерирует равномерно, вместо того
чтобы тратить каждый пятый прогон на значение, которое будет выброшено.

Размерные генераторы гарантируют **минимум**: `uniqueArrayOf`/`dictOf`
(различные элементы/ключи) и `commands` (применимые шаги) могут не добрать
*выпавший* размер, когда пространство значений исчерпано, но никогда не
опускаются ниже `$min` — недостижимый минимум бросает `GenerationExhaustedException`.

`Random` оборачивает объектный движок MT19937: два экземпляра с одним seed
дают идентичные последовательности независимо от других random-вызовов в
процессе — именно это делает seed из отчёта воспроизводимым. Не используйте
сгенерированные значения для криптографии.

### Swarm-тестирование (`Gen::swarm`)

Равномерная выборка из полного алфавита делает все случаи похожими друг на
друга: сотня значений из `oneOf('push', 'pop', 'flush')` почти наверняка содержит
все три, поэтому баги, которым нужно *отсутствие* операции, практически
недостижимы. `Gen::swarm()` ограничивает генератор выбора случайным непустым
подмножеством его вариантов на каждый сгенерированный случай — Groce et al.,
*Swarm Testing* (ISSTA 2012):

```php
Gen::swarm(Gen::oneOf('push', 'pop', 'flush'));   // в одном случае доступны, скажем, только 'pop' и 'flush'
Gen::swarm(Gen::commands($model, $commands));   // одна последовательность использует подмножество команд
```

Принимает генераторы выбора — `Gen::oneOf()`, `Gen::elements()`,
`Gen::frequency()`, `Gen::commands()` — и любой ваш `Swarmable`; всё остальное
отвергается исключением. Выжившие ветви `frequency` сохраняют свои веса: ветвь,
которая была вдвое вероятнее соседней, такой и остаётся.

Shrinking не выходит за подмножество, из которого получился случай:
контрпример, найденный без `flush`, никогда не зашринкается в содержащий `flush`, —
именно это и позволяет такой находке воспроизводиться. Два следствия, которые
стоит знать:

- подмножество берётся один раз на сгенерированное значение, поэтому
  оборачивать нужно тот генератор, чью область вы имеете в виду.
  `swarm(commands(...))` ограничивает всю последовательность;
  `arrayOf(swarm(oneOf(...)))` перевыбирает подмножество на каждый элемент —
  это шум, а не swarm-тестирование;
- контрпример сообщает значение, а не подмножество, из которого оно взято.
  Воспроизведение по seed восстанавливает и то и другое.

Swarm над `Gen::commands()` с ненулевым `$minLength` может оставить случай без
применимых команд; это бросает `GenerationExhaustedException` — ровно так же, как
неограниченный генератор, которого «заморила» модель.

### Зависимые генераторы (`flatMap`)

Когда домен одного входа зависит от другого — список плюс валидный индекс в
него, размер плюс payload этого размера — `Gen::flatMap()` передаёт каждое
сгенерированное значение в замыкание, возвращающее arbitrary финального
значения. В отличие от `Assume::that()`, прогоны не отбрасываются, и
сжимаются оба уровня:

```php
Gen::flatMap(
    Gen::nonEmptyArrayOf(Gen::int()),
    static fn(array $items): ArbitraryInterface => Gen::tuple(
        Gen::constant($items),
        Gen::intBetween(0, count($items) - 1), // всегда валидный индекс
    ),
);
```

### Композитные генераторы (`Gen::composite`)

Переиспользуемый генератор нескольких зависимых значений без вложенного
`flatMap`, уезжающего вправо на уровень с каждой зависимостью: тело берёт всё
нужное через `Draw` и возвращает построенное, а результат — обычный
`ArbitraryInterface`, который компонуется с `map`, `arrayOf`, провайдером:

```php
$interval = Gen::composite(static fn (Draw $d): Interval => new Interval(
    $min = $d->draw(Gen::datetime()),
    $d->draw(Gen::datetime(min: $min)),   // видит предыдущий draw
));
```

Сжатие работает по draw, ранние первыми: кандидат заново исполняет тело с
одним draw, заменённым на меньший; следующий за ним draw берётся заново из
потока своей позиции — то же значение, если его генератор не изменился, и
новое через новый диапазон, если изменился (`max` над сжатым `min`). Тело,
бросившее `Exception` на меньшем draw, отвергает кандидата — как
валидирующий конструктор отвергает значение под `map`; `Error` пробрасывается.
Другой случайности, кроме взятой через draw, тело не видит, поэтому каждый
кандидат — чистая функция ленты. Спуск ограничен той же глубиной, что и
in-body draw (1000 принятых шагов).

### Draw внутри тела (`Gen::draw`)

Когда несколько зависимых значений делают вложенный `flatMap` громоздким,
берите их прямо в теле property через `Gen::draw()` — валидно только пока
runner исполняет тело (иначе бросает). Взятые значения записываются на
replay-ленту, сжимаются как дополнительные параметры и попадают в контрпример
как `draw#1`, `draw#2`, ... При наличии draw принятые shrink-шаги ограничены
(1000 по умолчанию; явный `maxShrinks` побеждает) — это гарантирует
завершение.

### Заметки к контрпримеру (`Gen::note`)

Значение, вычисленное телом — разобранная форма строки, задержка, выбранная
backoff'ом, индекс, на который сел поиск, — в контрпримере не видно, если его
не несёт сообщение ассерта. `Gen::note('encoded', $encoded)` прикрепляет его к
текущему прогону; контрпример несёт заметки исходного упавшего прогона и
минимизированного (`CounterExample::$originalNotes` / `$shrunkNotes`), а
сообщение о падении печатает заметки минимизированного после аргументов:

```
  Shrunk:   s="a" (2 shrink step(s), 5 trial(s))
  Notes:    encoded="YQ=="
```

Заметки прошедшего прогона отбрасываются — цена одна запись в массив на
прогон. Заметки — не метки: `Classify` агрегирует по всему набору прогонов,
заметка принадлежит одному. Вне прогона `Gen::note()` бросает, как
`Gen::draw()`.

### Сжимаемая случайность (`Gen::randomEngine`)

Код, принимающий `Random\Randomizer` — backoff с джиттером, shuffle,
взвешенный выбор, — можно тестировать с вытянутым seed, но seed воспроизводит
падение, не сжимая его. `Gen::randomEngine()` даёт `Random\Engine`, каждый
`generate()` которого — один in-body draw из восьми байт: случайные решения за
падением записываются на ленту, реплеятся по позиции и сжимаются к `"\0"`,
что `getInt($min, $max)` отображает в `$min`, а `shuffleArray()` — в почти
тождественную перестановку. `Gen::randomizer()` оборачивает его в нативный
`Randomizer`, а `Gen::forParameters()` выводит оба из типа параметра
`Random\Engine` / `Random\Randomizer`:

```php
static function (int $attempt, \Random\Randomizer $randomizer): void {
    $delay = (new JitteredBackoff($randomizer))->delayMs($attempt);

    Assert::true($delay <= 60_000);
}
```

Каждый вызов движка — позиция ленты, так что спуск ограничен, как у любого
in-body draw; тело, потребляющее тысячи случайных значений за прогон,
сжимается лишь настолько, насколько позволяет этот предел. Валидно только
внутри прогона.

### Отбрасывание прогонов (`Assume`)

`Assume::that($condition)` отбрасывает текущую попытку, когда предусловие не
выполнено — попытка не считается ни падением, ни успешной проверкой, а `runs`
по-прежнему означает успешные проверки. Повторы ограничены `maxDiscards`
(по умолчанию `runs * 10`) со структурным `GaveUpException` при превышении.
Конструируйте валидные входы (`flatMap`/`draw`) вместо массового отбрасывания.

Skip'ы окружения — пропущенное тело или lifecycle-хук — считаются и
ограничиваются отдельно: о входе они не говорят ничего, поэтому исчерпание их
бюджета сообщает про окружение, а не про генераторы. Их неявный бюджет —
`runs`, а не `runs * 10`; явный `maxDiscards` управляет обоими.

### Распределение (`Classify`)

`Classify::label()` / `Classify::when()` считают метки по прогонам;
`Classify::cover($condition, $label, $minPercent)` превращает подсчёт в жёсткое
требование — прошедшая property с недобором метки падает как `CoverageFailed`.
Порог принадлежит метке: два `cover()` с одной меткой и разными процентами в
одном прогоне оставляют в силе последний.
Счётчики возвращаются в `RunStatistics::$classifications`; печать
distribution-отчёта — работа адаптера.

То же содержимое доступно как данные, без разбора печатной строки:
`PropertyFinished::$distribution` несёт `DistributionReport` — каждую метку как
`LabelShare` (счётчик, доля и порог `cover()`, с которым она была
зарегистрирована), `discardPercent()`, `unmetRequirements()` и `toArray()` для
телеметрии. Два знаменателя, разведённые по именам: доли меток считаются от
успешных проверок (discard их не разбавляет), доля discard'ов — от попыток.
Метка, которую потребовали и ни разу не получили, попадает в отчёт со
счётчиком 0, а не исчезает; `coverageAssessed` равен false, когда прогон
закончился до завершения цикла проверок, — отчёт не изображает вердикт,
которого не было. У фальсифицированного прогона распределения нет: он
останавливается на контрпримере.

`Classify::tabulate($table, $tags)` — наблюдательная половина этого
разделения: *категории* прогона, которых у него может быть несколько сразу —
`'payload'` × `'small'`/`'large'`, `'features'` × `['compressed', 'retried']`, —
агрегированные по таблицам в том же отчёте (`DistributionReport::$tables`, по
`LabelShare` на тег) вместе с попарными пересечениями тегов, встретившихся
вместе (`$intersections`, ключ `tagA & tagB`). Без гейта: `cover()` остаётся
единственным инструментом принуждения. `toArray()` добавляет ключ `tables`
только когда прогон табулировал.

### Конфигурация

`PropertyConfig` несёт все ручки движка — runner не читает окружение:

| Поле | Default | Значение |
|---|---|---|
| `runs` | 100 | Успешных проверок до завершения (discard'ы не считаются) |
| `seed` | `null` | Seed случайной фазы; null — случайный (попадает в отчёт об ошибке) |
| `maxShrinks` | `null` | Лимит принятых shrink-шагов; 0 отключает shrinking |
| `maxDiscards` | `null` | Порог для бюджета discard'ов **и** бюджета skip'ов, если задан. Оставленные неявными, они различаются: `runs * 10` для discard'ов и `runs` для skip'ов — машине, которая не может выполнить property, не нужно десять попыток на проверку, чтобы это сказать |
| `timeoutMs` | `null` | Wall-clock дедлайн одного прогона → `DeadlineExceeded`. Измеряется по возврату из тела: сообщает о прогоне, который превысил лимит, но не прерывает зависшее тело. Shrink-попытки не хронометрируются, как и discarded- и skipped-прогоны — тело, вышедшее через `Assume::that()` или skip фреймворка, ничего не проверило, и по дедлайну оно не судится |
| `budgetMs` | `null` | Wall-clock бюджет всей случайной фазы → `TimeBudgetExceeded` |
| `shrink` | `null` | `ShrinkMode::Off` отдаёт контрпример как сгенерирован; null = `Full` |
| `shrinkBudgetMs` | `null` | Wall-clock бюджет спуска; включает `ShrinkMode::Bounded` |
| `phases` | `null` | Выполняемые фазы (`Phase::Examples`/`Corpus`/`Random`/`Shrink`); null — все |
| `derandomize` | `false` | Выводить незаданный seed из id property, а не тянуть случайный |
| `edgeCases` | `EdgeCases::Mixin` | `None` выключает граничное смещение числовых генераторов — для property, которым края стоят только прогонов |
| `path` | `null` | Воспроизвести записанный спуск вместо повторного поиска; требует явный `seed` |
| `exhaustive` | `false` | Перебрать весь домен параметров вместо выборки, когда каждый генератор — `Enumerable`, а произведение умещается в `exhaustiveBudget` — см. [Исчерпывающий режим](#исчерпывающий-режим) |
| `exhaustiveBudget` | `10_000` | Наибольший домен, который обходит `exhaustive`; выше него фаза делает выборку, и отчёт говорит почему |
| `flakyReplays` | `2` | Повторные исполнения минимизированного контрпримера; прошедший помечает его flaky — см. [Детекция flaky](#детекция-flaky) |
| `searchRuns` | `0` | Сколько тел может исполнить целевой поиск после random-фазы, если тело сообщает `Target` — см. [Целевой поиск](#целевой-поиск-target) |

У всех трёх лимитов в миллисекундах общий потолок — `intdiv(PHP_INT_MAX, 2_000_000)`,
около 4.6e12 мс: раннер переводит их в наносекунды, и значение выше отвергается,
а не перестаёт молча быть дедлайном.

### Исчерпывающий режим

Для малого домена случайная выборка — вероятностное утверждение там, где
гарантия доступна и дёшева: четыре статуса × шесть триггеров = 24 входа. С
`exhaustive: true` random-фаза обходит всё произведение параметров — первый
параметр меняется медленнее всех, порядок каждого генератора свой (int по
возрастанию, `oneOf` как перечислено, `null` первым), — когда генератор каждого
параметра реализует `Enumerable` с конечным доменом, а произведение умещается в
`exhaustiveBudget`. Обход не зависит от seed, `runs` игнорируется в пользу
размера домена, отброшенный вход пропускается, а не перевыбирается,
фальсифицирующий — сжимается через своё дерево как обычно. In-body draw — не
параметры: внутри каждого входа они остаются случайными, с seed как всегда.

`Gen::constant()`, `bool()`, `intBetween()` (и `int()` — с размером, который
не примет ни один бюджет), `elements()`/`enum()`/`oneOf()`, `nullable()`,
`tuple()`, `record()`, `map()`, `filter()` (верхняя оценка — предикат
применяется при обходе) и `withEdgeCases()` над перечислимыми источниками —
`Enumerable`; обёртка над неограниченным источником отвечает `domainSize():
null`, и режим отказывается. Когда отказывается — параметр без конечного
домена или домен выше бюджета — фаза делает выборку, а
`DistributionReport::$exhaustiveDeclined` называет причину; когда обходит,
`$domainSize` говорит сколько. `toArray()` добавляет ключ `exhaustive` в
обоих случаях и не добавляет, когда флаг выключен. Реализуйте `Enumerable`
(`domainSize()` + `enumerate()`) на своём arbitrary, чтобы присоединиться.

### Детекция flaky

Фальсифицированная property сохраняет контрпример; если тело или тестируемый
код недетерминированы — часы, `mt_rand`, порядок неупорядоченной карты, —
следующий replay того же входа проходит, запись вычищается как «исцелённая»,
и сьют мигает. После спуска runner исполняет минимизированный вход ещё
`flakyReplays` раз (2 по умолчанию, 0 выключает): replay, упавший снова,
подтверждает контрпример; прошедший (или отброшенный) помечает его flaky —
`CounterExample::isFlaky()`, `$passedOnReplay` с номером replay и строка
`Flaky:` в сообщении, указывающая на недетерминизм, а не на вход. Контрпример
всё равно записывается; replay не порождают событий и стоят только времени.

### Целевой поиск (`Target`)

Часть багов живёт на экстремуме — самая долгая задержка, самая глубокая
рекурсия, самая полная очередь, — а равномерная выборка достигает экстремума
случайно. Тело, сообщающее оценку через `Target::maximize('delay', $delay)`
(или `minimize`), при `searchRuns > 0` получает после random-фазы фазу
поиска: лучшие по оценке входы хранятся в пуле, берётся один (лучшие — чаще),
один параметр генерируется заново, остальные сохраняются, результат
проверяется как любой прогон — лучшая оценка попадает в пул, падение
фальсифицирует property. Метки чередуются; каждый новый максимум — событие
`TargetImproved` с давшим его входом, а `SearchReport` прогона
(`PropertyFinished::$search`, `RunStatistics::$search`) несёт число
исполнений и по метке — направление, лучшую оценку, число улучшений и число
сохранённых входов, с которых поиск стартовал.

```php
static function (int $a, int $b, int $c): void {
    Target::maximize('sum', $a + $b + $c);

    Assert::true($a + $b + $c <= 2_900);   // 0.17% пространства
}
```

Замер по 100 seed при одинаковых 300 исполнениях: 300 случайных прогонов
нашли этот угол 52 раза, 200 случайных плюс 100 поисковых — 98. Поиск
двигается с гранулярностью параметра, поэтому находит экстремумы и углы; баг,
требующий подогнать один параметр к другому с точностью до единиц (`|a - b|
< 3` на широком диапазоне), он находит не чаще выборки, а оценка, не
связанная с багом, не помогает и не мешает. Направление метки фиксировано
на всю property; неконечная оценка отвергается на месте вызова. Property,
которая ничего не целит, ничего не платит и поиска не сообщает.

Передайте `Corpus`, реализующий ещё и `SearchCorpus` — `FilesystemCorpus` и
`RedisCorpus` реализуют оба, — и пул сохраняется в отдельный поисковый документ
(`<sha1(id)>.search.json`, ключ `:search`) после фазы и вспоминается перед
следующей, так что поиск продолжается с достигнутого; записи под другими
именами параметров или метка, которую тело теперь толкает в другую сторону,
игнорируются. Регрессионный документ и правила его recall/prune не затронуты.

### Дерандомизированные прогоны

Незаданный seed берётся случайно, поэтому property, падающая на одном входе из
пятидесяти, в CI падает через раз. Корпус это лечит — но только *после* того,
как первое падение записано. `derandomize: true` закрывает вторую сторону этого
момента:

```php
new PropertyConfig(derandomize: true);   // один и тот же id всегда выбирает одни и те же входы
```

Seed становится чистой функцией от id property: найденный локально баг
воспроизводится в CI, не дожидаясь записи в корпус, а у проходящей property
распределение входов стабильно — именно это делает метрики распределения
сравнимыми между коммитами. Явный `seed` всегда побеждает флаг. Отображение
seed→значения не меняется: меняется только то, какой seed выбирает прогон, а не
то, что этот seed порождает.

### Воспроизведение shrink-пути

Основная работа спуска уходит на отвергнутые кандидаты: самая маленькая
целочисленная property самого движка принимает девять шагов, испытав тридцать
девять кандидатов. Принятые шаги — последняя строка сообщения о провале, и то
же значение лежит на контрпримере, поэтому повторный прогон может пройти по
ним, а не искать их заново:

```text
  Failure:  value>50
  Path:     value:2/value:2/value:4/value:4
```

```php
$counterExample->path;                    // 'value:2/value:2/value:4/value:4'

new PropertyConfig(seed: 1, path: 'value:2/value:2/value:4/value:4');
```

Прогон, который ничего не зашринкал, пути не имеет — строка не печатается
пустой, а отсутствует.

Шаг называет узел — параметр или in-body draw под псевдоименем `draw#N` — и
номер принятого кандидата в перечислении shrink'ов этого узла. Воспроизведение
выполняет тело один раз на шаг, а не один раз на кандидата. Случайную фазу оно
не пропускает: чтобы дойти до упавшего прогона, нужно выполнить предыдущие —
тело может тянуть случайность через `Gen::draw()`, а discard'ы зависят от тела.

Путь — отладочный инструмент, а не фикстура. Его шаги — индексы кандидатов
shrink'а, поэтому правка генератора его осиротит; для этого существует
регрессионный корпус. Переставший применяться путь сообщается отдельным исходом
(`PathFailed`) с указанием сломавшегося шага и никогда не поглощается свежим
поиском: тихий поиск вернул бы контрпример, неотличимый от удачного
воспроизведения. Конфигурации, в которых путь стал бы no-op — без явного seed,
без фазы `Random` или `Shrink`, с выключенным shrinking, с wall-clock бюджетом
спуска, с `maxShrinks` меньше длины пути, с некорректным путём — отвергаются в
конструкторе.

### Режимы shrinking и фазы

`maxShrinks` ограничивает *принятые* шаги, но цена спуска — в *испытанных*
кандидатах: на больших коллекциях это легко дороже самой случайной фазы,
которая нашла падение. Две ручки ограничивают его с другой стороны:

```php
new PropertyConfig(shrink: ShrinkMode::Off);   // отдать контрпример как сгенерирован
new PropertyConfig(shrinkBudgetMs: 500);       // спускаться не дольше 500 мс, оставить лучшее
```

Бюджет спуска — единственная ручка пакета, которая стоит детерминизма: как
далеко уйдёт спуск, зависит от того, сколько работает тело, поэтому один и тот
же seed на быстрой и медленной машине минимизируется по-разному. Она отвечает
на «спуск завис», а не на «воспроизведи точно» — для второго пиньте seed или
полагайтесь на корпус.

Фазы прогона — множество, а не жёсткая последовательность:

```php
new PropertyConfig(phases: [Phase::Examples, Phase::Corpus]);  // быстрый гейт на PR
new PropertyConfig();                                          // все фазы (по умолчанию)
```

| Правило | Поведение |
|---|---|
| Пустое множество фаз | `InvalidArgumentException` — прогону без фаз не о чем отчитываться |
| Множество фаз с чем-либо кроме `Phase` | `InvalidArgumentException` — нераспознанная стадия просто не выполнится, и свойство отчитается зелёным, ничего не проверив |
| Множество без `Shrink` | Ровно `ShrinkMode::Off`; из двух ручек всегда побеждает более строгая |
| `Phase::Corpus` | Гейтит только **replay** корпуса и складывается с `replayRegressions` по И; новая фальсификация всё равно записывается |
| Множество без `Random` | Ничего не генерируется: честные нули (`attempts: 0`, `checks: 0`), требования покрытия отбрасываются, а не считаются от пустого знаменателя. Результат — `Passed`, если включённые более ранние фазы прошли: закреплённый пример или запись корпуса, которые падают, по-прежнему отчитываются своим отказом. Одно исключение из «ничего»: **seed**-запись корпуса реплеит целую random-фазу (не меньше `runsBeforeFailure + 1` попыток — столько, сколько нужно, чтобы дойти до записанного падения) и без `Random` — этот реплей и есть запись; эти попытки не random-фаза, и в нулевую статистику последующего `Passed` они не попадают |

`PropertyDefinition` добавляет идентичность (`id` ключует события и корпус),
отображаемое `name`, `generators`, `parameterNames`, фиксированные `examples`
(позиционные кортежи, выполняются до случайной фазы, никогда не сжимаются) и
`replayRegressions` (адаптеры выключают его, когда property пинит свой seed).

Переменные окружения `PROPERTY_RUNS` / `PROPERTY_SEED` / `PROPERTY_VERBOSE` /
`PROPERTY_DB` — конвенции **адаптеров**: адаптеры читают их и разрешают в
`PropertyConfig` и `Corpus`. Движок поставляет *смысл* значений, чтобы оба
адаптера понимали их одинаково: `EnvironmentOverrides::runs()` / `seed()` /
`phases()` / `edgeCases()` / `flag()` / `string()` разбирают сырое значение из
`getenv()` (не задано или пусто → `null`, испорчено →
`InvalidArgumentException` с именем переменной), а `CorpusFactory::fromDsn()`
превращает значение `PROPERTY_DB` в корпус: путь к каталогу —
`FilesystemCorpus`, `redis://host[:port][/db][?prefix=key-prefix&timeout=seconds]` (или
`rediss://` для TLS) — `RedisCorpus` поверх `ext-redis` или predis, любая
другая схема — ошибка; один и тот же экземпляр на одно значение в процессе.
Хелпера, который читает переменную за вас, нет — `fromDsn()` единственный
вход, поэтому harness не может получить бэкенд, которого DSN не называл.

Необязательный query-параметр `timeout` задаёт тайм-аут подключения Redis в
секундах (по умолчанию `5.0`) для клиентов Predis и ext-redis.

### Регрессионный корпус

Передайте `Corpus` в `PropertyRunner::run()` — каждая фальсификация
записывается; записанные падения реплеятся **до** случайной фазы: всё ещё
падающее репортится сразу (`RegressionFailed` для values-записи), переставшее
падать — вычищается. Без аргумента corpus — ни replay, ни обращений к
файловой системе.

`FilesystemCorpus` — встроенная реализация: один небольшой JSON-файл на
property (`<sha1(id)>.json`, максимум 8 values-записей и 2 seed-записи,
старейшие вытесняются; атомарная, сериализованная блокировкой запись). Формат
байт-совместим с корпусом, записанным `rasuvaeff/property-testing` 2.8 —
существующие корпуса продолжают работать после миграции. Запись, которую он
не смог завершить — lock-файл подменён симлинком, временный путь занят,
диск полон, rename не удался, документ принадлежит другой версии формата, —
бросает исключение, и раннер сообщает о нём событием `CorpusFailed`:
`CorpusStored` эмитится только после того, как документ оказался на диске, и
никогда — за запись, которая молча ничего не сделала. Исход самой property
в обоих случаях не меняется. Пустой путь к каталогу отвергается в
конструкторе.

| Запись (`CorpusEntry`) | Когда | Replay |
|---|---|---|
| Values | Каждый минимизированный аргумент представим как данные (null/скаляры/массивы/enum-case'ы/байтовые строки) | Один прогон с точным записанным входом |
| Seed | Объекты, замыкания или значения `Gen::draw()` в контрпримере | Вся случайная фаза с этим seed в том режиме `EdgeCases`, в котором падение было записано; отгораживается sequence epoch |

Корпус — единственная память property между прогонами, а большинство
фальсификаций случается в CI, на машине, которая исчезает вместе с job'ом.
Перенос корпуса между прогонами — три шага, и каждый существует из-за
конкретного тихого отказа (объединённый cache-action не сохраняет на красном
job'е — ровно тогда, когда корпус и был записан); рецепт и ловушки —
[The corpus as a CI artifact](https://rasuvaeff.github.io/property-testing-core/guide/regression-corpus#the-corpus-as-a-ci-artifact).

`RedisCorpus` — общий вариант: тот же документ, но в Redis вместо каталога, так
что падение, найденное на ноутбуке, воспроизводится в CI, а найденное в CI — на
следующем ноутбуке. Он принимает небольшой шов клиента (`CorpusClient`, с
`PhpRedisCorpusClient` для `ext-redis` и `PredisCorpusClient` для predis) и
пишет оптимистично (чтение, compare-and-set, повтор), а не под блокировкой,
после нескольких попыток тихо сдаваясь: корпус — это память, а не журнал, и
ронять прошедший прогон ради записи контрпримера — неверный размен.

```php
$corpus = new RedisCorpus(new PhpRedisCorpusClient($redis));
```

Общий корпус — это общее пространство значений: values-запись есть вывод
генератора, и её прочтёт всякий, кто имеет доступ к этому Redis.

Оба backend'а реализуют и `SearchCorpus` — адаптивную базу примеров
[целевого поиска](#целевой-поиск-target): отдельный документ на property с
лучшими входами по каждой метке `Target`, заменяемый целиком после каждой фазы
поиска. С регрессионными записями он никогда не смешивается.

Values-запись хранит падающий вход **дословно**, поэтому каталог корпуса
настолько же чувствителен, насколько чувствительны данные, которые производят
ваши генераторы. Обычно это неинтересно — случайные числа и строки, — но
генератор, засеянный продакшн-фикстурой, или собирающий правдоподобные
персональные либо похожие на учётные данные, запишет ровно их на диск в JSON
открытым текстом. Держите каталог вне общедоступных путей и вне публикуемых
артефактов сборки, а такие значения лучше синтезировать внутри тела property,
а не генерировать: тогда в контрпример попадёт seed, а не сами данные.

### Id property и замыкания (`PropertyId`)

Id property ключует и события, которые агрегирует listener, и запись корпуса,
воспроизводящую вчерашний контрпример, — значит завтра он должен называть ту же
property. Адаптер, выводящий его из бэктрейса, получает это для метода теста и
теряет для замыкания: стабильного имени у замыкания в PHP никогда не было.

```text
PHP 8.3   Suite::{closure}
PHP 8.4+  Suite::{closure:/app/tests/StackTest.php:19}
```

На 8.3 все замыкания класса схлопываются в один id, и две property одного файла
затирают контрпримеры друг друга; с 8.4 id содержит номер строки — вставка
строки выше осиротит вчерашнюю запись. Ни там, ни там ничего не бросается:
корпус просто перестаёт воспроизводить падение, ради которого он и существует.

`PropertyId::unstableWarning($id)` возвращает фразу, которую следует показать
для такого id, или `null`, если говорить не о чем. Это диагноз, а не лечение:
движок возвращает текст, печатает адаптер — сам движок никуда не пишет.
Лечение — адаптеры, позволяющие назвать property явно
(`forAll($generators)->id($id)` в PHPUnit-адаптере): переданный id берётся как
есть.

### События и listeners

Передайте реализации `PropertyListener` в `PropertyRunner::run()` и наблюдайте
весь жизненный цикл — так подключаются console reporter, экспортёр телеметрии
или IDE-интеграция без каких-либо изменений движка:

| Событие | Когда |
|---|---|
| `PropertyStarted` / `PropertyFinished` | Вокруг всей property (id, seed, runs / финальный failure или null) |
| `ExampleStarted` / `ExampleFinished` | Вокруг каждого явного example |
| `RunStarted` / `RunPassed` / `RunDiscarded` / `RunFailed` | Вокруг каждого случайного прогона (аргументы, draws, метки, время) |
| `ShrinkTried` / `ShrinkAccepted` | На каждый shrink-кандидат / принятый шаг |
| `TargetImproved` | Прошедший прогон дал новый максимум по метке `Target` (метка, направление, оценка, предыдущий максимум, вход) — в random-фазе или в фазе поиска |
| `CorpusReplayed` / `CorpusPruned` / `CorpusStored` | Активность корпуса; `CorpusStored` — только после подтверждённой записи |
| `CorpusFailed` | Корпус бросил исключение (Redis недоступен, ошибка клиента, незавершённая запись на диск, нечитаемый или незаписываемый поисковый документ); property продолжила без него до конца прогона |

События несут только данные движка — никогда типы фреймворков. Исключение
listener'а прерывает прогон (падение наблюдателя — инфраструктурная авария, а
не то, что нужно прятать), и listener никогда не меняет исход property. См.
[`examples/custom_listeners.php`](examples/custom_listeners.php) — console
reporter и telemetry-коллектор, построенные чисто на событиях.

### Детерминированное время (`Clock`)

Runner меряет дедлайны и бюджеты через абстракцию `Clock` — `MonotonicClock`
(default, на `hrtime`) в бою, fake clock в тестах, инъекция через конструктор
`PropertyRunner`. Именно это делает поведение `timeoutMs`/`budgetMs` точно
тестируемым.

### Собственный arbitrary

Любое пространство значений достижимо прямой реализацией
`ArbitraryInterface`: `generate(Random)` возвращает `Shrinkable` — значение
плюс ленивое дерево меньших кандидатов, самые агрессивные первыми, каждый со
своим поддеревом. Случайность — только через инъецированный `Random`
(`int()`, `float()`, `bytes()`), чтобы seed-прогоны оставались
воспроизводимыми. `Shrinkable::leaf($value)` строит терминальный узел;
`Shrinkable::of($value, $closure)` подвешивает лениво вычисляемых кандидатов;
`Shrinkable::map($fn)` преобразует всё дерево. Держите каждую ветвь конечной
и никогда не отдавайте кандидата, равного родителю — это и гарантирует
завершение shrinking.

### Stateful / model-based тестирование

Часть багов проявляется только на *последовательности* операций. Реализуйте
`Command` (`preCondition` / `nextState` / `run` / `postCondition` плюс
`__toString`-метка), генерируйте валидные последовательности через
`Gen::commands()` и прогоняйте их `StateMachine::check()` внутри тела
property — упавший postcondition бросает `PostconditionViolationException` с номером
шага, а падающая `CommandSequence` сжимается до кратчайшей ломающей
последовательности:

```php
$definition = new PropertyDefinition(
    id: 'demo::stackBehavesLikeItsModel',
    name: 'stackBehavesLikeItsModel',
    generators: ['sequence' => Gen::commands([], [
        Gen::map(Gen::intBetween(0, 99), static fn(int $v): Command => new Push($v)),
        Gen::constant(new Pop()),
    ])],
    parameterNames: ['sequence'],
    config: new PropertyConfig(runs: 200),
);

$result = (new PropertyRunner())->run($definition, new CallableTrialExecutor(
    static function (CommandSequence $sequence): void {
        StateMachine::check($sequence, static fn(): Stack => new Stack());
    },
));
```

### Rule-машины (`Gen::rules`)

`Command` остаётся примитивом для машины, чья модель — отдельное значение.
Для обычного случая — модель это несколько полей — достаточно одного класса:
методы `#[Rule]` — шаги (параметры берутся как у property, с переопределениями
из `public static function <rule>Generators(): array` или метода, названного
в атрибуте), `#[Precondition('guard')]` называет bool-метод, при false
пропускающий шаг, а методы `#[Invariant]` выполняются перед первым шагом и
после каждого исполненного. Исключение — упавший postcondition.

```php
final class QueueMachine
{
    private array $model = [];

    public function __construct(private readonly Queue $sut) {}

    #[Rule]
    public function enqueue(int $value): void
    {
        $this->sut->push($value);
        $this->model[] = $value;
    }

    #[Rule]
    #[Precondition('notEmpty')]
    public function dequeue(): void
    {
        Assert::same($this->sut->pop(), array_shift($this->model));
    }

    public function notEmpty(): bool { return $this->model !== []; }

    #[Invariant]
    public function sizeMatches(): void
    {
        Assert::same($this->sut->size(), count($this->model));
    }
}

// generators: ['sequence' => Gen::rules(QueueMachine::class, maxLength: 50)]
static function (RuleSequence $sequence): void {
    $sequence->run(static fn (): QueueMachine => new QueueMachine(new Queue()));
}
```

`RuleSequence::run()` каждый раз строит свежую машину через фабрику — фабрика
остаётся в теле, так что последовательность — простое значение: печатается
как трасса (`[enqueue(value: 0), enqueue(value: 1), dequeue()]`) и
сериализуется внутри результата. Последовательности генерируются и сжимаются
ровно как у `commands()`: шаги сбрасываются, аргументы упрощаются. Машину без
правил, правило не-public или статическое, несуществующий guard `Gen::rules()`
отвергает по имени.

### Экспорт контрпримера

`CounterExample` открывает `seed`, `runsBeforeFailure`, `originalArguments`,
`shrunkArguments`, `shrinkSteps`, `shrinkTrials`, `discards`, `skips`,
`originalNotes`/`shrunkNotes`, `replays`/`passedOnReplay` и исходный
`failure`; `toArray()`/`toJson()` возвращают нормализованную машинную форму, а
`toExamplesCode()` печатает исполняемый PHP, пиняющий сжатый случай как
постоянный example. Контрпример, который невозможно воспроизвести example'ом —
с неэкспортируемым объектом или значениями in-body `Gen::draw()` — бросает
`LogicException` вместо генерации сломанного кода; такие случаи реплеятся
через seed.

`ValueRenderer::render($value)` даёт однострочную человеческую форму значения —
ту же, что и в сообщении о контрпримере (строки в кавычках и с экранированием,
массивы и объекты сворачиваются, рекурсия и глубина ограничены). Адаптеры
переиспользуют его, чтобы verbose-вывод читался так же, как сообщение о провале.

### Отладка генераторов

`Gen::sample($arb, $count, $seed)` жадно генерирует значения;
`Gen::sampleShrinks($arb, $seed)` показывает одно значение плюс его первые
shrink-кандидаты — быстрейший способ проверить, что кастомный arbitrary
сжимается как задумано.

## Политика совместимости

Что обещает номер версии — чтобы обновление минора было решением, которое
можно принять, не читая дифф.

| № | Предмет | Обещание |
|---|---|---|
| 1 | **Границы** | Публичный API — все типы с `@api`. Типы с `@internal` — реализация, меняются в любом релизе, включая патч. |
| 2 | **Seed → значения** | *Не* под SemVer. Минор может сдвинуть то, что порождает конкретный seed; тогда в том же релизе поднимается `FilesystemCorpus::SEQUENCE_EPOCH`, а изменение называется в changelog — записи-seed'ы прошлой эпохи отбрасываются, а не воспроизводят другой вход. Записи-значения переживают любой релиз. `CounterExample::$path` — отладочная подсказка, а не долговечный идентификатор: он индексирует кандидатов shrink'а, поэтому правка генератора его осиротит. |
| 3 | **Формат корпуса** | `FilesystemCorpus::FORMAT_VERSION` не меняется внутри 1.x. Документ растёт только опциональными полями; документ, записанный любым релизом 0.x (или `rasuvaeff/property-testing` 2.8), остаётся читаемым. Redis-бэкенд пишет побайтово тот же документ. |
| 4 | **Тексты сообщений** | Человекочитаемые тексты исключений и предупреждений могут быть переформулированы в миноре с записью в changelog. Это проза для разработчика, читающего красный прогон, а не поверхность для парсинга. Заморожена машинная форма: ключи `CounterExample::toArray()`/`toJson()` и `DistributionReport::toArray()`, поля каждого `@api`-результата и события. |
| 5 | **События** | Новый тип события или новое поле в конце существующего — минор. Удаление события или поля, перестановка последовательности для существующего исхода — мажор. Новые реализации `PropertyResult` — минор: потребитель обязан иметь ветку по умолчанию. |
| 6 | **Конструкторы** | Конструкторы `@api` `final readonly`-классов — append-only: новые параметры с значениями по умолчанию и только в конец, поэтому позиционное конструирование продолжает работать. |
| 7 | **Как достаются данные** | Заморожено как есть, и единообразия здесь нет: результаты, события и большинство исключений отдают публичные `readonly`-свойства, а `PropertyViolationException`, `ExampleViolationException`, `PathViolationException` и `RegressionViolationException` — геттеры. Оба стиля остаются. Приведение к одному сломало бы всех потребителей ради косметики, а расхождение по крайней мере устойчиво: у типа либо поле, либо геттер, и одно не превращается в другое. |
| 8 | **Семейство** | Адаптеры (`-testo`, `-phpunit`) и `-names` требуют движок каретой на текущий мажор. Мажор здесь — мажор там, и движок выпускается первым. |
| 9 | **PHP** | `8.3 - 8.5`. Поддержка нового минора PHP — патч, расширяющий constraint; снятие версии PHP — мажор. |

Пункт 2 уже срабатывал: 0.6.0 сменила распределение `Gen::string()` и подняла
`SEQUENCE_EPOCH` до 2, а 0.5.0 сменила порядок кандидатов shrink'а списков.
Оба были минорами, и оба верны — движок property-тестирования, который никогда
не может улучшить распределение генератора, заморожен на своей первой ошибке.
Регрессию через такое изменение переносит корпус — поэтому записи-значения
освобождены, а записи-seed'ы огорожены.

## Безопасность

Движок сам не делает I/O, SQL, shell и сетевых операций; единственный доступ к
файловой системе — опциональный `FilesystemCorpus`, и только когда вы сами
передаёте его runner'у. Случайные значения — из движка MT19937, посеянного
seed'ом из отчёта: это PRNG, не CSPRNG; никогда не используйте
сгенерированные значения для криптографии, а seed трактуйте как ручку
воспроизводимости, не как секрет.

Если корпус всё же включён — помните, что он сохраняет падающие входы в JSON
открытым текстом: что это значит для генераторов, способных произвести
чувствительные данные, — в разделе
[Регрессионный корпус](#регрессионный-корпус).

## Примеры

Исполняемые скрипты — в [examples/](examples/).

| Скрипт | Показывает | Нужен сервер? |
|---|---|---|
| `basic.php` | property, которая выполняется; property, которая фальсифицируется; shrinking по дереву | Нет |
| `generators.php` | `sample`, boundary bias, `uuid`, `datetime`, `dictOf`, `record`, `flatMap` | Нет |
| `standalone_runner.php` | прямое управление движком: `PropertyDefinition`, `CallableTrialExecutor`, структурный `PropertyResult` | Нет |
| `for_class.php` | `Gen::forClass()` с psalm-аннотациями и без, override на один параметр, валидирующий конструктор в обоих режимах | Нет |
| `swarm.php` | swarm-тестирование над choice-генератором и над `Gen::commands()`; спуск, не выходящий за подмножество | Нет |
| `targeted_search.php` | угловой баг, который 300 случайных прогонов пропускают, а 200 случайных + 100 поисковых с `Target::maximize()` находят | Нет |
| `rule_machine.php` | rule-based stateful-фасад против корректного стека и FIFO-стека, сжатого до кратчайшего свидетеля | Нет |
| `custom_listeners.php` | console reporter и telemetry-коллектор как чистые `PropertyListener` | Нет |
| `case-studies/regex-anchor.php` | валидатор с якорем `$`, принимающий завершающий перевод строки | Нет |
| `case-studies/saturating-minus.php` | вычитание, дающее отрицательную длительность вместо насыщения | Нет |
| `case-studies/backoff-cap.php` | jitter, добавленный после потолка и выводящий задержку за него | Нет |
| `case-studies/hash-bucketing.php` | rollout-hash, посоленный процентом, ломающий монотонность | Нет |
| `case-studies/faker-vs-property.php` | один и тот же баг на реалистичных и на shrink'аемых данных — фальсифицируют оба, минимизирует один | Нет |

## Разработка

PHP/Composer на хосте нет. Команды выполняются в Docker через образ `composer:2`:

```bash
docker run --rm -v "$PWD":/app -w /app composer:2 composer install
docker run --rm -v "$PWD":/app -w /app composer:2 composer build
docker run --rm -v "$PWD":/app -w /app composer:2 composer cs:fix
docker run --rm -v "$PWD":/app -w /app composer:2 composer test
docker run --rm -v "$PWD":/app -w /app composer:2 composer release-check
```

Или через Make:

```bash
make install
make build
make cs-fix
make test
make test-coverage
make mutation
make release-check
```

`make test-coverage` и `make mutation` поднимают `pcov` внутри контейнера
`composer:2`, потому что в базовом образе нет coverage-драйвера.

## Лицензия

[BSD-3-Clause](LICENSE.md)
