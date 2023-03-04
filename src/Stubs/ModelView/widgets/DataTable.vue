<template>
	
	<data-table
		title="PascalCaseModelName" 
		:data-url="dataUrl"
		:policy-url="policyUrl"
		:model="model"
		:external-filters="camelCaseModelNameExternalFilters"
		:form-filters="formFilters"
		:extra-params="extraParams"
		:hide-columns="hideColumns"
		:card-wrapper="cardWrapper"
		:show-topbar="showTopbar"
		:show-title="showTitle"
		:has-actions="hasActions" 
		:has-filter="hasFilter" >

		<template v-slot:filterForm>
			
			<filter-form @submit="updateFormFilters" />

		</template>

	</data-table>

</template>

<script>
	
	import DataTable from 'innoboxrr-vue-datatable'
	import FilterForm from '@models/kebabcasemodelname/forms/FilterForm'
	import * as model from '@models/kebabcasemodelname/model.js' 

	export default {

		components: {
			
			DataTable,
			
			FilterForm ,

		},

		props: {

			showTopbar:{
				type: Boolean,
				default: true
			},
			
			showTitle: {
				type: Boolean,
				default: true
			},

			showBreadcrumb: {
				type: Boolean,
				default: false
			},

			hasActions: {
				type: Boolean,
				default: true
			},

			hasFilter: {
				type: Boolean,
				default: true
			},

			externalFilters: {
				type: Object,
				default: {}
			},

			extraParams: {
				type: Object,
				default: {}
			},

			hideColumns: {
				type: Array,
				default: []
			},

			cardWrapper: {
				type: Boolean,
				default: true
			},

		},		

		data() {

			return {
			
				dataUrl: route('api.snake_case_model_name.index'),

				policyUrl: route('api.snake_case_model_name.policies'),

				model: model,

				formFilters: {}
			
			}

		},

		computed: {

			camelCaseModelNameExternalFilters() {

				let filters = {/* Add custom filters */}

				return {
					...this.externalFilters,
					...filters
				}

			}

		},

		methods: {

			updateFormFilters(filters) {

				this.formFilters = filters;

			},

		}

	}

</script>