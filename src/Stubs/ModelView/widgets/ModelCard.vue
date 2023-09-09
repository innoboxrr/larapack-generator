<template>
	
	<div class="uk-card uk-card-default uk-card-body uk-border-rounded uk-margin uk-padding-small">

	    <div class="flex justify-end">

	        <button 
	        	class="inline-block text-gray-500 mdark:text-gray-400 hover:bg-gray-100 mdark:hover:bg-gray-700 focus:ring-4 focus:outline-none focus:ring-gray-200 mdark:focus:ring-gray-700 rounded-lg text-sm p-1.5" 
	        	type="button">
	            
	            <span class="sr-only">Open dropdown</span>

	            <svg class="w-5 h-5" aria-hidden="true" xmlns="http://www.w3.org/2000/svg" fill="currentColor" viewBox="0 0 16 3">

	                <path d="M2 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Zm6.041 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3ZM14 0a1.5 1.5 0 1 1 0 3 1.5 1.5 0 0 1 0-3Z"/>

	            </svg>

	        </button>

	        <!-- Dropdown menu -->
	        <div 
	        	uk-dropdown="mode: click;"
	        	class="uk-padding-remove z-10 hidden text-base list-none bg-white divide-y divide-gray-100 rounded-lg shadow w-44 mdark:bg-gray-700">

	            <ul class="py-2" aria-labelledby="dropdownButton">

		            <li>

		                <router-link
		                	:to="{
		                		name: 'AdminShowPascalCaseModelName',
		                		params: {
		                			id: camelCaseModelName.id
		                		}
		                	}" 
		                	class="uk-link-reset block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">

		                	<i class="fa-solid fa-eye pr-2"></i> Mostrar

		                </router-link>

		            </li>

		            <li>

		                <router-link
		                	:to="{
		                		name: 'AdminEditPascalCaseModelName',
		                		params: {
		                			id: camelCaseModelName.id
		                		}
		                	}" 
		                	class="uk-link-reset block px-4 py-2 text-sm text-gray-700 hover:bg-gray-100">

		                	<i class="fa-solid fa-pen-to-square pr-2"></i> Editar

		                </router-link>

		            </li>

		            <li>

		                <a 
		                	@click="deletePascalCaseModelName" 
		                	class="block px-4 py-2 text-sm text-red-600 hover:bg-gray-100 mdark:hover:bg-gray-600 mdark:text-gray-200 mdark:hover:text-white">

		                	<i class="fa-solid fa-trash-can pr-2"></i> Eliminar

		                </a>

		            </li>

	            </ul>

	        </div>

	    </div>

	    <div class="flex flex-col items-center pb-10">
	        
	        <img 
	        	class="w-24 h-24 mb-3 rounded-full shadow-sm" 
	        	src="https://picsum.photos/536/354" />

	        <h5 class="mb-1 text-xl font-medium text-gray-900 mdark:text-white">
	        	
	        	Título de la tarjeta

	        </h5>

			<div v-if="false" class="flex mt-4 space-x-3 md:mt-6">

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
	
	        // Asegurarse de que al menos uno de los dos props esté definido.
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