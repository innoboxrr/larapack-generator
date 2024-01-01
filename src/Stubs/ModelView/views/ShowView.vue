<template>

	<div v-if="dataLoaded">

		<breadcrumb-component :items="items" />
	    
	    <div class="uk-container uk-container-expand">

	    	<div class="uk-grid-small" uk-grid>
	    		
	    		<div class="uk-width-1-3@m uk-width-1-1@s">

					<model-card 
						:kebabcasemodelname="camelCaseModelName" />

	    		</div>

	    		<div class="uk-width-expand uk-width-1-2@m uk-width-1-1@s">

	    			<div v-if="this.isShowView">

	    				<model-profile 
	    					:kebabcasemodelname="camelCaseModelName" />
	    				
	    			</div>

	    			<div v-else>
	    				
	    				<router-view @updateData="fetchData"></router-view>

	    			</div>

	    		</div>

	    	</div>

	    </div>

	</div>

</template>

<script>

	import { showModel } from '@models/kebabcasemodelname'
	import ModelCard from '@models/kebabcasemodelname/widgets/ModelCard.vue'
	import ModelProfile from '@models/kebabcasemodelname/widgets/ModelProfile.vue'

	export default {

		components: {

			ModelCard,

			ModelProfile

		},

		mounted() {

			this.fetchData();

		},

		data() {
		
			return {

				dataLoaded: false,

				title: undefined,

				camelCaseModelNameId: this.$route.params.id,

				camelCaseModelName: {},

			}
		
		},

		computed: {

			isShowView() {

				return (this.$route.name == 'AdminShowPascalCaseModelName');

			},

			items() {

				if(this.$route.name == 'AdminShowPascalCaseModelName') {

					return [
						{ text: 'PluralPascalCaseModelName', path: '/admin/kebabcasemodelname'},
						{ text: this.kebabcasemodelname.name ?? 'PascalCaseModelName', path: '/admin/kebabcasemodelname/' + this.kebabcasemodelname.id}
					];

				} else if(this.$route.name == 'AdminEditPascalCaseModelName') {

					return [
						{ text: 'PluralPascalCaseModelName', path: '/admin/kebabcasemodelname'},
						{ text: this.kebabcasemodelname.name ?? 'PascalCaseModelName' , path: '/admin/kebabcasemodelname/' + this.kebabcasemodelname.id},
						{ text: 'Editar kebabcasemodelname', path: '/admin/kebabcasemodelname/' + this.kebabcasemodelname.id + '/edit'}	
					];

				}

			}

		},

		methods: {

			fetchData() {

				this.fetchPascalCaseModelName().then( res => {

					this.dataLoaded = true;
					
					this.title = this.camelCaseModelName.name;

					document.title = this.title;

				});

			},

			fetchPascalCaseModelName() {

				return new Promise((resolve, reject) => {

					showModel(this.camelCaseModelNameId).then( res => {

	                    this.camelCaseModelName = res.data;

	                    resolve(res);

	                }).catch( error => {

	                    reject(error);

	                });

				});

            },

		}

	}

</script>