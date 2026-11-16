import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { createRequire } from 'node:module';
import test from 'node:test';
import { parse } from '@vue/compiler-sfc';
import { compile } from '@vue/compiler-ssr';
import { createSSRApp, defineComponent } from 'vue';
import { renderToString } from 'vue/server-renderer';

const require = createRequire(import.meta.url);
const source = readFileSync(new URL('../../resources/js/Components/EventRegistrationModal.vue', import.meta.url), 'utf8');
const { descriptor } = parse(source);
const { code } = compile(descriptor.template.content);
const ssrRender = new Function('require', code)(require);
const wrapper = defineComponent({ inheritAttrs: false, setup: (_, { slots }) => () => slots.default?.() });
const emptyIcon = defineComponent({ setup: () => () => null });

async function confirmation(registration, event = {}) {
    const app = createSSRApp({
        ssrRender,
        setup: () => ({
            props: { event }, show: true, currentStep: 4,
            STEPS: { EMAIL_CHECK: 1, USER_FORM: 2, PAYMENT: 3, CONFIRMATION: 4 },
            stepTitles: { 4: 'Регистрация завершена' },
            registrationResult: registration,
            closeModal: () => {},
        }),
    });
    for (const name of ['TransitionRoot', 'TransitionChild', 'Dialog', 'DialogPanel', 'DialogTitle']) {
        app.component(name, wrapper);
    }
    for (const name of ['XMarkIcon', 'CheckCircleIcon', 'ArrowRightIcon', 'ArrowLeftIcon', 'ExclamationCircleIcon', 'TextInput', 'PhoneInput', 'SocialLoginButtons']) app.component(name, emptyIcon);
    return renderToString(app);
}

test('an existing account sees registration and queued mail without a new-account notice', async () => {
    const html = await confirmation({ is_new_user: false, email_queued: true });
    assert.match(html, /Регистрация успешна!/);
    assert.match(html, /поставлено в очередь/);
    assert.doesNotMatch(html, /Мы создали/);
});

test('only a newly created account sees the account notice', async () => {
    assert.match(await confirmation({ is_new_user: true, email_queued: true }), /Мы создали/);
    assert.doesNotMatch(await confirmation(null), /Мы создали|поставлено в очередь/);
});

test('an existing registration does not promise another email', async () => {
    const html = await confirmation({ is_new_user: false, already_registered: true, email_queued: null });
    assert.match(html, /Вы уже зарегистрированы/);
    assert.doesNotMatch(html, /Мы создали|поставлено в очередь|будут отправлены/);
});

test('mail failure preserves registration success and displays the contact', async () => {
    const html = await confirmation({ is_new_user: false, email_queued: false });
    assert.match(html, /Регистрация успешна!/);
    assert.match(html, /Не удалось подготовить письмо/);
    assert.match(html, /mailto:info@mag-expert.ru/);
    assert.doesNotMatch(html, /поставлено в очередь/);
});

test('on-demand applications retain their manager follow-up message', async () => {
    const html = await confirmation({ is_new_user: true, email_queued: true }, { is_on_demand: true });
    assert.match(html, /С вами свяжется менеджер/);
    assert.doesNotMatch(html, /Мы создали|поставлено в очередь/);
});
