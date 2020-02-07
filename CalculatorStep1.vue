<template>
    <div v-if="step==1" class="row">
        <div class="col-md-12" v-show="loading">
            <div class="col-md-9 col-md-offset-1">
                <i class="fa fa-spinner fa-spin fa-4x" style="text-align: center;margin: auto;width:100%;"></i>
            </div>
        </div>
        <div class="col-md-12" v-show="(!loading && edit != 1)" >
            <div class="col-md-9 col-md-offset-1">
                <h1 class="title">{{ trans('calculator.step1.title') }}</h1> <!-- param 1-->
                <p>{{ trans('calculator.step1.description') }}</p><br>
                <!-- param 2-->

                <div class="form-group">
                    <single-select-visa name="visa_id" id="fc-visa_id"
                                        classes="form-control visa_id"
                                        v-model="criteria.visa">
                    </single-select-visa>
                    <!-- param 3-->
                </div>
                <br>
                <div class="form-group">
                    <label class="control-label">
                        {{ trans('calculator.step1.age') }}<span class="symbol required"></span></label>
                    <select class="form-control" name="agestatus" id="agestatus" v-model="criteria.ageStatus">
                        <option value="">{{ trans('calculator.please-select') }}</option>
                        <option value="Over">{{ trans('calculator.yes') }}</option>
                        <option value="Under">{{ trans('calculator.no') }}</option>
                    </select>
                </div>
                <br>
                
                
                <div class="form-group">
                    <label class="control-label">{{ trans('calculator.step1.country') }}<span class="symbol required"></span></label>
                    <select class="form-control" name="countries" v-model="criteria.country">
                        <option value="">{{ trans('calculator.please-select') }}</option>
                        <option v-for="item in items" :value="item"> {{ item[display] }}</option>
                    </select>
                </div>
                <br>
				
				<div class="form-group">
                    <label class="control-label">{{ trans('calculator.step1.campus') }}<span class="symbol required"></span></label>
                    <select class="form-control" name="campuses" v-model="criteria.campus">
                        <option value="">{{ trans('calculator.please-select') }}</option>
                        <option v-for="item in allcampuses" :value="item"> {{ item.name }}</option>
                        <option value=null> {{ trans('calculator.either-campus') }}</option>
                    </select>
                </div>
				<br>
				
                <div class="row">
                    <div  style="text-align: center;">
                        <!--<input type="submit" class="btn-lg btn-primary brn-wide" value="Start Calculations">-->
                        <button :disabled="(criteria.visa=='' || criteria.visa=='0') || (criteria.country=='' || criteria.country=='0') || (criteria.ageStatus=='' || criteria.ageStatus=='0') || (criteria.campus=='' || criteria.campus=='0')"
                                class="btn-lg btn-primary"
                                @click="startCalculations">{{ trans('calculator.step1.start-calculations') }}</button>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <calculator-step-2 v-show="!loading" v-else-if="step==2" :status.sync="status" :criteria="criteria" :quoteobj="quoteobj" :countries="items"></calculator-step-2>

</template>

<script>
    module.exports = {
        props: ['id', 'name', 'displayField', 'valueField', 'edit', 'quotation'],
        data(){
            return {
                loading: false,
                criteria: {
                    ageStatus: '',
                    country: '',
                    visa: '',
                    campus: '',
                },
                step: 1,
                display: this.displayField ? this.displayField : 'name',
                value: this.valueField ? this.valueField : 'id',
                items: [],
                quoteobj: {},
                healthObj: {},
                status: {edit:0},
                allcampuses:[]
            }
        },
        methods: {
            startCalculations: function () {
                this.step = 2;
            },
            load: function() {
                this.create();
                this.loadAllCampuses();
                if (this.edit == 1)
                    this.findQuote();
            },
            create: function(){
                this.loading = true;
                this.$http.get('/api/quote/create', {params:auth}).then((response) => {
                    this.items = response.data;
                    this.loading = false;
                },(response) =>{
                        console.log('Error', this);
                        this.loading = false;
                    })
            },

            findQuote: function() {
                this.loading = true;
                this.$http.post('/api/quote/findquote', {data: this.quotation}).then((response) => {
                    this.quoteobj = response.data;
                    this.criteria.ageStatus = this.quoteobj.age_status;
                    if(this.quoteobj.country==null){
                        this.criteria.country = {id:'other', name:'Other'};
                    }
                    else {
                        this.criteria.country = this.quoteobj.country;
                    }

                    this.criteria.visa = this.quoteobj.visas;
                    if(this.quoteobj.campus_id){
                        this.criteria.campus = this.quoteobj.campus;
                    }
                    else {
                        this.criteria.campus = 'null';
                    }

                    this.step = 2;
                    this.loading = false;
                },
                    (response) =>
                    {
                        console.log('Error', this);
                        this.loading = false;
                    }
                );
            },
            loadAllCampuses: function() {
                this.$http.get('/api/quote/campuses').then((response) => {
                    this.allcampuses=response.data;
                }, (response) => {
                    console.log('Visa Error',response);
                });
            }
        },
        mounted() {
            this.load();
            this.status.edit = this.edit;
        }

    }
</script>

