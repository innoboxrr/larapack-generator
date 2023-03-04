<template>

	<div id="AdminPluralPascalCaseModelNameWrapper">

		<div v-if="isHome" class="uk-section uk-section-xsmall">
			
			<data-table 
				:show-title="false"
				:key="crudKey" />

		</div>

		<div v-else>

			<router-view @updateData="updateDatatable"></router-view>
			
		</div>

	</div>

</template>

<script>
	
	import DataTable from '@models/kebabcasemodelname/widgets/DataTable'

	export default {

		components: {

			DataTable

		},

		data() {

			return {

				title: undefined,

				crudKey: 0,

			}

		},

		computed: {

			isHome() {

				return (this.$route.name == 'AdminPluralPascalCaseModelName');

			}

		},

		methods: {

			updateDatatable() {

				++this.crudKey;

			},

		}

	}

</script>