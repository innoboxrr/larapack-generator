import { Link } from 'react-router-dom'
import { classFor } from 'innoboxrr-form-core'

/**
 * Las acciones de un registro.
 *
 * Antes cada rama las pintaba a su manera: Vue delegaba en un
 * <DropdownButtonComponent> que no existe en ningun paquete del ecosistema, y
 * React repetia el marcado en linea dentro de ModelCard. El contrato de
 * `items` ya era el mismo en las dos, asi que lo unico que faltaba era el
 * componente.
 *
 *     { type: 'router', to, label }
 *     { type: 'event', action, label, danger? }
 *
 * @param {{ items: Array<Record<string, any>> }} props
 */
export default function ActionMenu({ items }) {
    return (
        <div className={classFor('actionMenu')}>

            {items.map((action) => (
                action.type === 'router' ? (
                    <Link
                        key={action.label}
                        to={action.to}
                        className={classFor('actionMenuItem')}>{action.label}</Link>
                ) : (
                    <button
                        key={action.label}
                        type="button"
                        className={classFor(action.danger ? 'actionMenuDanger' : 'actionMenuItem')}
                        onClick={action.action}>{action.label}</button>
                )
            ))}

        </div>
    )
}
