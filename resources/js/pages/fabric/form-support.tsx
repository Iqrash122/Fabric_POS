import { router } from '@inertiajs/react';
import {
    createContext,
    useContext,
    useEffect,
    useId,
    useMemo,
    useRef,
} from 'react';
import type { ReactNode, RefObject } from 'react';

const UNSAVED_MESSAGE = 'You have unsaved changes. Leave without saving them?';

const DirtyScopeContext = createContext<string | null>(null);
const dirtySources = new Map<string, string | null>();
let removeInertiaBefore: (() => void) | null = null;
let unloadInstalled = false;
let allowNextVisit = false;
let allowResetTimer: ReturnType<typeof setTimeout> | null = null;

function hasDirtySources() {
    return dirtySources.size > 0;
}

function beforeUnload(event: BeforeUnloadEvent) {
    if (!hasDirtySources()) return;
    event.preventDefault();
    event.returnValue = '';
}

function installGlobalGuards() {
    if (typeof window === 'undefined') return;

    if (!unloadInstalled) {
        window.addEventListener('beforeunload', beforeUnload);
        unloadInstalled = true;
    }

    if (!removeInertiaBefore) {
        removeInertiaBefore = router.on('before', (event) => {
            if (allowNextVisit) {
                allowNextVisit = false;
                return;
            }
            if (!hasDirtySources()) return;
            if (!window.confirm(UNSAVED_MESSAGE)) event.preventDefault();
        });
    }
}

function uninstallGlobalGuardsWhenIdle() {
    if (typeof window === 'undefined' || hasDirtySources()) return;
    if (unloadInstalled) {
        window.removeEventListener('beforeunload', beforeUnload);
        unloadInstalled = false;
    }
    removeInertiaBefore?.();
    removeInertiaBefore = null;
}

export function allowNextUnsavedNavigation() {
    allowNextVisit = true;
    if (allowResetTimer) clearTimeout(allowResetTimer);
    allowResetTimer = setTimeout(() => {
        allowNextVisit = false;
        allowResetTimer = null;
    }, 1000);
}

export function DirtyScope({
    scope,
    children,
}: {
    scope: string;
    children: ReactNode;
}) {
    return (
        <DirtyScopeContext.Provider value={scope}>
            {children}
        </DirtyScopeContext.Provider>
    );
}

export function useUnsavedChanges(isDirty: boolean) {
    const reactId = useId();
    const sourceId = useMemo(() => `dirty-${reactId}`, [reactId]);
    const scope = useContext(DirtyScopeContext);

    useEffect(() => {
        if (isDirty) {
            dirtySources.set(sourceId, scope);
            installGlobalGuards();
        } else {
            dirtySources.delete(sourceId);
            uninstallGlobalGuardsWhenIdle();
        }

        return () => {
            dirtySources.delete(sourceId);
            uninstallGlobalGuardsWhenIdle();
        };
    }, [isDirty, scope, sourceId]);
}

export function confirmDirtyScopeClose(scope: string) {
    const dirty = [...dirtySources.values()].some((value) => value === scope);
    return !dirty || window.confirm(UNSAVED_MESSAGE);
}

function errorMessage(errors: object, name: string): string | undefined {
    const value = (errors as Record<string, unknown>)[name];
    return typeof value === 'string' && value ? value : undefined;
}

function errorId(name: string, prefix = '') {
    const safeName = name.replace(/[^a-zA-Z0-9_-]+/g, '-');
    const safePrefix = prefix.replace(/[^a-zA-Z0-9_-]+/g, '-');
    return `${safePrefix ? `${safePrefix}-` : ''}error-${safeName}`;
}

export function fieldErrorProps(
    errors: object,
    name: string,
    prefix = '',
): {
    'aria-invalid'?: true;
    'aria-describedby'?: string;
    'data-error-field'?: string;
} {
    if (!errorMessage(errors, name)) return {};
    return {
        'aria-invalid': true,
        'aria-describedby': errorId(name, prefix),
        'data-error-field': name,
    };
}

export function FieldError({
    errors,
    name,
    prefix = '',
}: {
    errors: object;
    name: string;
    prefix?: string;
}) {
    const message = errorMessage(errors, name);
    if (!message) return null;
    return (
        <em className="field-error" id={errorId(name, prefix)}>
            {message}
        </em>
    );
}

export function ErrorSummary({
    errors,
    title = 'Please correct the highlighted fields.',
}: {
    errors: object;
    title?: string;
}) {
    const messages = Object.values(errors).filter(
        (value): value is string => typeof value === 'string' && value.length > 0,
    );
    if (!messages.length) return null;

    return (
        <div className="error error-summary" role="alert" aria-live="assertive">
            <strong>{title}</strong>
            <ul>
                {messages.map((message, index) => (
                    <li key={`${message}-${index}`}>{message}</li>
                ))}
            </ul>
        </div>
    );
}

export function useFirstErrorFocus(
    errors: object,
): RefObject<HTMLFormElement | null> {
    const formRef = useRef<HTMLFormElement>(null);
    const signature = Object.entries(errors)
        .map(([key, value]) => `${key}:${value}`)
        .join('|');

    useEffect(() => {
        if (!signature) return;
        const frame = window.requestAnimationFrame(() => {
            const target = formRef.current?.querySelector<HTMLElement>(
                '[aria-invalid="true"], [data-error-field]',
            );
            if (target) {
                target.focus({ preventScroll: true });
                target.scrollIntoView({ behavior: 'smooth', block: 'center' });
                return;
            }
            formRef.current
                ?.querySelector<HTMLElement>('input, select, textarea, button')
                ?.focus({ preventScroll: true });
        });
        return () => window.cancelAnimationFrame(frame);
    }, [signature]);

    return formRef;
}
