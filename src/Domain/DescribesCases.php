<?php

declare(strict_types=1);

namespace EarthlingOllie\SystemOneClassification\Domain;

/**
 * For an enum whose case descriptions come from code, such as translated text.
 *
 * When an enum implements it, every option is described by describe() and any #[Describe]
 * attribute on its cases is ignored. It is read when the question is built, and the text is
 * part of the question: a handle rebuilt under another locale is another question, which
 * Result::get() refuses. Read a result with the handle the decision asked.
 */
interface DescribesCases
{
    /** The description of this case, shown to the model next to its backing value. */
    public function describe(): string;
}
