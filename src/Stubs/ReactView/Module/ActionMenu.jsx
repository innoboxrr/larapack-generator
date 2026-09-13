import { useNavigate } from 'react-router-dom'
import { classFor } from 'innoboxrr-form-core'
import { IconComponent, MenuComponent } from 'innoboxrr-react-form-elements'
import t from 'innoboxrr-i18n'

/**
 * Las acciones de un registro, en un menú desplegable.
 *
 * Antes cada rama las pintaba a su manera, y después las dos como una lista de
 * enlaces siempre abierta. Ahora es el MenuComponent de
 * innoboxrr-react-form-elements, el mismo que usa la rama Vue: se abre con el
 * teclado, se cierra con Escape y al pulsar fuera, y devuelve el foco al botón.
 *
 *     { type: 'router', to, label, icon? }
 *     { type: 'event', action, label, icon?, danger? }
 *
 * @param {{ items: Array<Record<string, any>>, label?: string }} props
 */
export default function ActionMenu({ items, label = t('Actions') }) {
    const navigate = useNavigate()

    const menuItems = items.map((item, index) => ({
        id: item.id ?? `${item.label}-${index}`,
        label: item.label,
        icon: item.icon,
        danger: item.danger === true,
        disabled: item.disabled === true,
        disabledReason: item.disabledReason,
        action: item.type === 'router' ? () => navigate(item.to) : item.action,
    }))

    return (
        <MenuComponent
            items={menuItems}
            label={label}
            renderTrigger={({ toggle, loading, triggerProps }) => (
                <button
                    type="button"
                    className={classFor('iconButton')}
                    {...triggerProps}
                    disabled={loading}
                    onClick={toggle}>
                    <IconComponent name="more" size={16} />
                </button>
            )} />
    )
}
