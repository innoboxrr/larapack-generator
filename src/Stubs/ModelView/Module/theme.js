/**
 * Las clases CSS que comparten los formularios del modulo.
 *
 * Antes venian de un mixin global que la aplicacion anfitriona tenia que
 * registrar (`inputClass`, `buttonClass`). El codigo generado las usaba sin
 * declararlas en ningun sitio, asi que el modulo no se podia montar fuera de
 * esa aplicacion — ni probar.
 *
 * Cambialas aqui y cambian en todos los modelos del paquete.
 */

export const inputClass = 'uk-input uk-form-large uk-border-rounded'

export const selectClass = 'uk-select uk-form-large uk-border-rounded'

export const textareaClass = 'uk-textarea uk-form-large uk-border-rounded'

export const buttonClass = 'uk-button uk-width-1-1 button'
