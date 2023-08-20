<template>

	<div v-if="dataLoaded" class="uk-section uk-section-small">
	    
	    <div class="uk-container uk-container-expand">

	    	

	    </div>

	</div>

</template>

<script>

	import { showModel } from '@models/kebabcasemodelname'

	export default {

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