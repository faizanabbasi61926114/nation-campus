/**
 * Clock readers, so a screen asks for "today" in one place instead of building
 * the same `new Date().toISOString()` chain inline.
 *
 * They are functions rather than constants because the values must be read when
 * a form is opened, not when the module is first loaded.
 */
export const todayISO = () => new Date().toISOString().slice(0, 10)
export const monthISO = () => new Date().toISOString().slice(0, 7)
