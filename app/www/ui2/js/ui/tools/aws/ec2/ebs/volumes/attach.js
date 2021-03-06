Scalr.regPage('Scalr.ui.tools.aws.ec2.ebs.volumes.attach', function (loadParams, moduleParams) {
	return Ext.create('Ext.form.Panel', {
		width: 700,
		title: 'Tools &raquo; Amazon Web Services &raquo; EBS &raquo; Volumes &raquo; ' + loadParams['volumeId'] + ' &raquo;Attach',
		fieldDefaults: {
			anchor: '100%'
		},

		items: [{
			xtype: 'fieldset',
			title: 'Attach options',
			labelWidth: 130,
			items: [{
				fieldLabel: 'Server',
				xtype: 'combo',
				name:'serverId',
				allowBlank: false,
				editable: true,
				forceSelection: true,
				autoSearch: false,
                selectOnFocus: true,
                anyMatch: true,
                selectSingleRecordOnPartialMatch: true,
				store: {
					fields: [ 'id', 'name', 'farmName', 'farmRoleName'],
					data: moduleParams.servers,
					proxy: 'object'
				},
				value: '',
				displayField: 'name',
				valueField: 'id',
				queryMode: 'local',
                cls: 'x-boundlist-alt',
                tpl:
                    '<tpl for=".">' +
                        '<div class="x-boundlist-item" style="height: auto; width: auto">' +
                            '<div class="x-semibold">{name}</div>' +
                            '<tpl if="farmName && farmRoleName">' +
                                '<div <div style="line-height: 28px;">{farmName} &rarr; {farmRoleName}</div>' +
                            '</tpl>' +
                        '</div>' +
                    '</tpl>',
				listeners: {
					added: function() {
						this.setValue(this.store.getAt(0).get('id'));
					}
				}
			}]
		}, {
			xtype: 'fieldset',
			title: 'Always attach this volume to selected server',
			collapsed: true,
			hidden: !Scalr.flags['showDeprecatedFeatures'],
			checkboxName: 'attachOnBoot',
			checkboxToggle: true,
			labelWidth: 100,
			items: [{
				xtype: 'fieldcontainer',
				hideLabel: true,
				layout: 'hbox',
				items: [{
					xtype:'checkbox',
					name:'mount',
					inputValue: 1,
					checked: false
				}, {
					xtype:'displayfield',
					margin: '0 0 0 3',
					value:'Automatically mount this volume after attach to '
				}, {
					xtype:'textfield',
					name:'mountPoint',
					margin: '0 0 0 3',
					value:'/mnt/storage',
					cls: 'x-form-check-wrap'
				}]
			}]
		}],

		dockedItems: [{
			xtype: 'container',
			dock: 'bottom',
			cls: 'x-docked-buttons',
			layout: {
				type: 'hbox',
				pack: 'center'
			},
			items: [{
				xtype: 'button',
				text: 'Attach',
				handler: function() {
					Scalr.Request({
						processBox: {
							type: 'action',
							msg: 'Attaching ...'
						},
						form: this.up('form').getForm(),
						url: '/tools/aws/ec2/ebs/volumes/xAttach',
						params: loadParams,
						success: function () {
							Scalr.event.fireEvent('redirect',
								'#/tools/aws/ec2/ebs/volumes/' + loadParams['volumeId'] +
								'/view?cloudLocation=' + loadParams['cloudLocation']
							);
						}
					});
				}
			}, {
				xtype: 'button',
				text: 'Cancel',
				handler: function() {
					Scalr.event.fireEvent('close');
				}
			}]
		}]
	});
});
