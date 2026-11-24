import { router } from '@inertiajs/core';
import type { VueWrapper } from '@vue/test-utils';
import { flushPromises, mount } from '@vue/test-utils';
import {
    afterEach,
    beforeEach,
    describe,
    expect,
    it,
    vi,
} from 'vite-plus/test';
import type { Component } from 'vue';
import type { GenerateFormOptions, PricingData } from '@/types';

const options: GenerateFormOptions = {
    sizes: ['1024x1024', '1536x1024', '1024x1536'],
    qualities: ['low', 'medium', 'high'],
    defaultSize: '1024x1024',
    defaultQuality: 'medium',
    maxPromptLength: 4000,
    maxReferenceImages: 4,
};

const tokensBySize = {
    '1024x1024': 1100,
    '1536x1024': 1650,
    '1024x1536': 1650,
};

const pricing: PricingData = {
    rates: {
        input_text_tokens: 5_000,
        input_image_tokens: 10_000,
        output_tokens: 40_000,
        reasoning_tokens: 12_500,
    },
    tokens: {
        characters_per_text_token: 4,
        text_token_variance_percent: 20,
        per_reference_image: 1_000,
        output: { low: tokensBySize, medium: tokensBySize, high: tokensBySize },
        reasoning: {
            low: { min: 0, max: 500 },
            medium: { min: 200, max: 2_000 },
            high: { min: 1_000, max: 5_000 },
        },
    },
    markup: { numerator: 5, denominator: 4 },
};

type Submitted = Record<string, unknown>;

let submitted: Submitted[];

/** Pixelforge accepts every Generate: the server redirects as on a successful submit. */
function acceptEverySubmit(): void {
    submitted = [];
    vi.spyOn(router, 'post').mockImplementation((_url, data, visit = {}) => {
        submitted.push(JSON.parse(JSON.stringify(data)) as Submitted);
        visit.onSuccess?.({} as never);
        visit.onFinish?.({} as never);
    });
}

// Live validation (Precognition) is the server's concern, not this test's.
function skipLiveValidation(): void {
    vi.spyOn(globalThis, 'fetch').mockImplementation(() =>
        Promise.resolve(
            new Response(null, {
                status: 204,
                headers: {
                    Precognition: 'true',
                    'Precognition-Success': 'true',
                },
            }),
        ),
    );
}

/** A fresh GenerateForm module, so nothing is carried over between tests. */
async function generateForm(): Promise<Component> {
    vi.resetModules();

    return (await import('@/components/GenerateForm.vue')).default;
}

function mountForm(form: Component): VueWrapper {
    return mount(form, {
        props: { pricing, options, availableBalance: 10_000_000_000 },
        attachTo: document.body,
    });
}

async function choose(
    wrapper: VueWrapper,
    trigger: string,
    label: string,
): Promise<void> {
    await wrapper
        .get(trigger)
        .trigger('pointerdown', { button: 0, pointerType: 'mouse' });
    await flushPromises();

    const option = [...document.querySelectorAll('[role="option"]')].find(
        (element) => element.textContent?.trim() === label,
    );

    if (!(option instanceof HTMLElement)) {
        throw new Error(`No "${label}" option to choose`);
    }

    option.dispatchEvent(
        new PointerEvent('pointerup', { bubbles: true, pointerType: 'mouse' }),
    );
    await flushPromises();
}

async function addReference(wrapper: VueWrapper, url: string): Promise<void> {
    const add = wrapper
        .findAll('button')
        .find((button) => button.text() === 'Add reference image');

    if (add === undefined) {
        throw new Error('No "Add reference image" button');
    }

    await add.trigger('click');
    await wrapper.get('#reference-image-0').setValue(url);
}

async function generate(wrapper: VueWrapper, prompt: string): Promise<void> {
    await wrapper.get('#prompt').setValue(prompt);
    await wrapper.get('form').trigger('submit');
    await flushPromises();
}

/** Generate a landscape, high-quality lighthouse with a reference image. */
async function generateLighthouse(wrapper: VueWrapper): Promise<void> {
    await choose(wrapper, '#size', '1536 × 1024 (landscape)');
    await choose(wrapper, '#quality', 'High');
    await addReference(wrapper, 'https://example.com/style.png');
    await generate(wrapper, 'A lighthouse on a cliff at dawn');
}

describe('GenerateForm', () => {
    beforeEach(() => {
        acceptEverySubmit();
        skipLiveValidation();
    });

    afterEach(() => {
        vi.restoreAllMocks();
        document.body.innerHTML = '';
    });

    it('clears the prompt and the reference images after a submit, and keeps the size and quality', async () => {
        const wrapper = mountForm(await generateForm());

        await generateLighthouse(wrapper);

        expect(
            (wrapper.get('#prompt').element as HTMLTextAreaElement).value,
        ).toBe('');
        expect(wrapper.find('#reference-image-0').exists()).toBe(false);

        await generate(wrapper, 'A lighthouse at night');

        expect(submitted).toEqual([
            {
                prompt: 'A lighthouse on a cliff at dawn',
                size: '1536x1024',
                quality: 'high',
                reference_images: ['https://example.com/style.png'],
            },
            {
                prompt: 'A lighthouse at night',
                size: '1536x1024',
                quality: 'high',
                reference_images: [],
            },
        ]);
    });

    it('starts the next form with the size and quality of the last submit', async () => {
        const form = await generateForm();
        await generateLighthouse(mountForm(form));

        const next = mountForm(form);

        expect((next.get('#prompt').element as HTMLTextAreaElement).value).toBe(
            '',
        );
        expect(next.find('#reference-image-0').exists()).toBe(false);

        await generate(next, 'A lighthouse at night');

        expect(submitted.at(-1)).toEqual({
            prompt: 'A lighthouse at night',
            size: '1536x1024',
            quality: 'high',
            reference_images: [],
        });
    });

    it('starts with the default size and quality before any submit', async () => {
        const wrapper = mountForm(await generateForm());

        await generate(wrapper, 'A lighthouse');

        expect(submitted).toEqual([
            {
                prompt: 'A lighthouse',
                size: '1024x1024',
                quality: 'medium',
                reference_images: [],
            },
        ]);
    });
});
