// Общие классы полей для страниц-форм админки — одинаково во всех разделах
export const box = "space-y-4 border-t border-zinc-200 pt-6 first:border-t-0 first:pt-0 dark:border-zinc-800";
export const boxTitle = "text-base font-semibold text-zinc-900 dark:text-white";
export const label = "mb-1 block text-sm font-medium text-zinc-800 dark:text-zinc-200";
export const input = "block w-full rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm text-zinc-900 placeholder-zinc-400 transition-colors hover:border-zinc-400 focus:border-brandblue focus:outline-none focus:ring-1 focus:ring-brandblue disabled:cursor-not-allowed disabled:bg-zinc-100 disabled:text-zinc-500 dark:border-zinc-600 dark:bg-zinc-800 dark:text-white dark:hover:border-zinc-500 dark:focus:border-brandblue dark:focus:ring-brandblue dark:disabled:bg-zinc-900";
export const checkbox = "mt-0.5 size-4 shrink-0 rounded border-zinc-300 text-brandblue-dark focus:ring-brandblue dark:border-zinc-600 dark:bg-zinc-800";
export const hint = "mt-1 text-xs text-zinc-500 dark:text-zinc-400";
export const hintInline = "block text-xs text-zinc-500 dark:text-zinc-400";
export const error = "mt-1 text-sm text-red-600 dark:text-red-400";
export const pill = "cursor-pointer rounded-lg border border-zinc-300 px-4 py-2 text-sm font-medium text-zinc-700 dark:border-zinc-600 dark:text-zinc-200";
export const pillOn = "!border-brandblue-dark bg-brandblue-dark !text-white dark:!border-brandblue dark:bg-brandblue dark:!text-white";

// Классы списков: строка целиком кликабельна, действия — иконки справа
export const iconAction = "inline-flex size-9 items-center justify-center rounded-lg text-zinc-500 hover:bg-zinc-100 hover:text-zinc-900 dark:text-zinc-400 dark:hover:bg-zinc-800 dark:hover:text-white";
export const iconDanger = "inline-flex size-9 items-center justify-center rounded-lg text-red-600 hover:bg-red-50 dark:text-red-400 dark:hover:bg-red-900/20";
export const primaryButton = "inline-flex items-center justify-center gap-2 rounded-lg bg-brandblue-dark px-4 py-2 text-sm font-medium text-white transition-colors hover:bg-brandblue-deep dark:bg-brandblue dark:text-white dark:hover:bg-brandblue/90";

// Выбор файла: своя кнопка вместо системного «Выберите файл»
export const fileButton = "inline-flex cursor-pointer items-center gap-2 rounded-lg border border-zinc-300 bg-white px-3 py-2 text-sm font-medium text-zinc-800 transition-colors hover:bg-zinc-50 focus-within:ring-1 focus-within:ring-brandblue dark:border-zinc-600 dark:bg-zinc-800 dark:text-zinc-100 dark:hover:bg-zinc-700";
