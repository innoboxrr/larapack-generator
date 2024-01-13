<template>
	
	<div class="uk-card uk-card-default uk-card-body uk-border-rounded uk-margin uk-padding-small">

	    <div class="flex justify-end">

			<dropdown-button-component 
				:items="[
					{
						type: 'router',
						to: {
							name: 'AdminShowPascalCaseModelName',
							params: {
								id: camelCaseModelName.id
							}
						},
						label: 'Mostrar',
					},
					{
						type: 'router',
						to: {
							name: 'AdminEditPascalCaseModelName',
							params: {
								id: camelCaseModelName.id
							}
						},
						label: 'Editar',
					},
					{
						type: 'event',
						action: deletePascalCaseModelName,
						label: 'Eliminar',
					},
				]"/>

	    </div>

	    <div class="flex flex-col items-center pb-10">
	        
	        <img 
				v-if="false"
	        	class="w-24 h-24 mb-3 rounded-full shadow-sm" 
	        	src="https://picsum.photos/536/354" />

	        <h5 class="mb-1 text-xl font-medium text-gray-900 mdark:text-white">
	        	
	        	Título de la tarjeta

	        </h5>

			<div 
				v-if="false" 
				class="flex mt-4 space-x-3 md:mt-6">

				<a 
	            	class="inline-flex items-center px-4 py-2 text-sm font-medium text-center text-white hover:text-white bg-blue-700 rounded-lg hover:bg-blue-800 focus:ring-4 focus:outline-none focus:ring-blue-300">

	            	Botón A

	            </a>

	            <a 
	            	class="uk-link-reset inline-flex items-center px-4 py-2 text-sm font-medium text-center text-gray-900 bg-white border border-gray-300 rounded-lg hover:bg-gray-100 focus:ring-4 focus:outline-none focus:ring-gray-200 mdark:bg-gray-800 mdark:text-white mdark:border-gray-600 mdark:hover:bg-gray-700 mdark:hover:border-gray-700 mdark:focus:ring-gray-700">

	            	Botón B

	            </a>
        	
        	</div>

	    </div>

	</div>

</template>

<script>
	
	export default {

		props: {

			camelCaseModelName: {
				type: Object,
				required: false,
			},

			camelCaseModelNameId: {
				type: [Number, String],
				required: false
			}

		},

		created() {
	
	        if (!this.camelCaseModelName && !this.camelCaseModelNameId) {
	
	            console.error("Se debe proporcionar 'camelCaseModelName' o 'camelCaseModelNameId'.");
	
	        }
	
	    },

	    methods: {

	    	deletePascalCaseModelName() {

	    		deleteModel(this.camelCaseModelName).then( res => {

	    			this.$router.push({ name: 'AdminPluralPascalCaseModelName' });

	    		})

	    	}

	    }

	}

</script>