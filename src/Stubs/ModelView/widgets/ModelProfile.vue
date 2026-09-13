<template>

    <section v-if="record" class="fe-card">

        <header class="fe-toolbar">

            <div>
                <h2>{{ t('Details') }}</h2>
                <p class="fe-text-muted fe-text-sm">{{ t('Additional information') }}</p>
            </div>

            <span class="fe-toolbar-spacer" />

            <span class="fe-badge">{{ t('ID') }}: {{ record.id }}</span>

        </header>

        <dl class="fe-card-body">
            <div>
                <dt class="fe-text-muted fe-text-sm">{{ t('Created at') }}</dt>
                <dd>{{ formatDate(record.created_at) }}</dd>
            </div>
            <!-- Agrega mas campos aqui segun sea necesario -->
        </dl>

    </section>

</template>

<script setup>

    import { computed, onMounted, ref } from 'vue'
    import t from 'innoboxrr-i18n'

    import { showModel } from '../index'

    const props = defineProps({
        camelCaseModelName: {
            type: Object,
            default: null,
        },
        camelCaseModelNameId: {
            type: [Number, String],
            default: null,
        },
    })

    // La version anterior asignaba el resultado sobre el prop, lo que Vue
    // prohibe: el dato cargado vive en un ref propio.
    const fetched = ref(null)

    const record = computed(() => props.camelCaseModelName ?? fetched.value)

    // La API manda la fecha en ISO; se enseña en el idioma de la página.
    const formatDate = (value) => (value
        ? new Intl.DateTimeFormat(document.documentElement.lang || undefined, { dateStyle: 'medium', timeStyle: 'short' }).format(new Date(value))
        : '')

    onMounted(async () => {

        if (props.camelCaseModelName || ! props.camelCaseModelNameId) {
            return
        }

        fetched.value = await showModel(props.camelCaseModelNameId)

    })

</script>
