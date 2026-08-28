const FORM_CONTROL_SELECTOR = 'input, select, textarea';
let generatedControlId = 0;

function ignoredControl(control) {
    const type = (control.getAttribute?.('type') ?? '').toLowerCase();

    return ['hidden', 'button', 'submit', 'reset', 'image'].includes(type);
}

export function controlHasAccessibleName(control) {
    if (!control || ignoredControl(control)) return true;
    if (control.getAttribute?.('aria-label')?.trim()) return true;
    if (control.getAttribute?.('aria-labelledby')?.trim()) return true;
    if (control.closest?.('label')) return true;

    const id = control.id?.trim();
    if (!id) return false;

    return [...(control.ownerDocument?.querySelectorAll?.('label[for]') ?? [])]
        .some((label) => label.htmlFor === id && label.textContent?.trim());
}

function localLabel(control) {
    let container = control.parentElement;

    for (let depth = 0; container && depth < 3; depth += 1, container = container.parentElement) {
        const controls = [...(container.querySelectorAll?.(FORM_CONTROL_SELECTOR) ?? [])]
            .filter((candidate) => !ignoredControl(candidate));
        const labels = [...(container.querySelectorAll?.('label') ?? [])]
            .filter((label) => label.textContent?.trim())
            .filter((label) => !label.htmlFor && !label.contains?.(control));

        if (controls.length === 1 && controls[0] === control && labels.length === 1) return labels[0];
        if (container.matches?.('form, fieldset, [data-gf-shell]')) break;
    }

    return null;
}

export function enhanceControlAccessibility(control) {
    if (controlHasAccessibleName(control)) return false;

    const label = localLabel(control);
    if (label) {
        if (!control.id) {
            generatedControlId += 1;
            control.id = `gf-field-${generatedControlId}`;
        }
        label.htmlFor = control.id;

        return true;
    }

    const parentLabel = control.parentElement?.getAttribute?.('aria-label')?.trim();
    if (parentLabel) {
        control.setAttribute?.('aria-label', parentLabel);

        return true;
    }

    const placeholder = control.getAttribute?.('placeholder')?.trim();
    if (!placeholder) return false;
    control.setAttribute?.('aria-label', placeholder);

    return true;
}

export function enhanceFormAccessibility(root = document) {
    const controls = root.matches?.(FORM_CONTROL_SELECTOR)
        ? [root]
        : [...(root.querySelectorAll?.(FORM_CONTROL_SELECTOR) ?? [])];

    controls.forEach(enhanceControlAccessibility);
}
