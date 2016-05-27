( function( ui, $, wp ) {
	var data = {
		processActive: false,
		processInfo: {},
		hasMoreLogs: true
	};

	ui.template = false;

	ui.init = function() {
 		if ( ! wp.heartbeat ) {
 			console.error( ui.l10n.missingHeartbeat );
 			return;
 		}

 		if ( ! wp.ajax || ! wp.template ) {
 			console.error( ui.l10n.missingUtil );
 			return;
 		}

 		if ( ! $( ui.selectors.progress ).length ) {
 			console.error( ui.l10n.invalidProgressSelector );
 			return;
 		}

 		if ( ! $( ui.selectors.startButton ).length ) {
 			console.error( ui.l10n.invalidStartButtonSelector );
 			return;
 		}

 		$( document ).on( 'heartbeat-send', function( e, heartbeatData ) {
 			heartbeatData.requestBackgroundProcessInfo = ui.processIdentifier;

 			if ( data.processInfo.logs && data.processInfo.logs.length ) {
 				heartbeatData.backgroundProcessInfoLatestLogId = data.processInfo.logs[0].id;
 			}
 		});

 		$( document ).on( 'heartbeat-tick', function( e, response ) {
 			if ( ! response.backgroundProcessInfo ) {
 				ui.setProcessInfo({});
 				ui.setProcessActive( false );
 			} else {
 				if ( data.processInfo.logs && data.processInfo.logs.length ) {
 					response.backgroundProcessInfo.logs = response.backgroundProcessInfo.logs.concat( data.processInfo.logs );
 				}
 				if ( response.backgroundProcessInfo.progress >= response.backgroundProcessInfo.total ) {
 					ui.setProcessActive( false );
 				} else {
 					ui.setProcessActive( true );
 				}
 				if ( 0 === response.backgroundProcessInfo.total ) {
 					response.backgroundProcessInfo.percentage = 100;
 				} else {
 					response.backgroundProcessInfo.percentage = parseInt( ( response.backgroundProcessInfo.progress / response.backgroundProcessInfo.total ) * 100, 10 );
 				}
 				ui.setProcessInfo( response.backgroundProcessInfo );
 			}
 		});

 		$( document ).on( 'click', ui.selectors.startButton, function( e ) {
 			wp.ajax.post( 'start_background_process_' + ui.processIdentifier, {
 				nonce: ui.processNonce
 			}).done( function( response ) {
 				ui.setProcessInfo({});
 				ui.setProcessActive( true );
 			}).fail( function( message ) {
 				console.error( message );
 			});

 			e.preventDefault();
 		});

 		$( document ).on( 'click', ui.selectors.emptyLogsButton, function( e ) {
 			wp.ajax.post( 'empty_old_background_process_' + ui.processIdentifier + '_logs', {
 				nonce: ui.processNonce
 			}).done( function( response ) {
 				ui.setProcessInfo({});
 			}).fail( function( message ) {
 				console.error( message );
 			});

 			e.preventDefault();
 		});

 		$( document ).on( 'click', '#logs-more', function( e ) {
 			wp.ajax.post( 'get_background_process_' + ui.processIdentifier + '_logs', {
 				nonce: ui.processNonce,
 				key: data.processInfo.key,
 				beforeId: data.processInfo.logs.length ? data.processInfo.logs[ data.processInfo.logs.length - 1 ].id : false
 			}).done( function( response ) {
 				if ( 25 > response.length ) {
 					data.hasMoreLogs = false;
 				}
 				data.processInfo.logs = data.processInfo.logs.concat( response );
 				ui.refreshContent();
 			}).fail( function( message ) {
 				console.error( message );
 			});

 			e.preventDefault();
 		});

 		wp.heartbeat.connectNow();

 		ui.setProcessInfo({});
 		ui.setProcessActive( ui.processActive );
	};

	ui.setProcessInfo = function( info ) {
		data.processInfo = info;

		if ( data.processInfo.logs && 0 === data.processInfo.logs.length % 25 ) {
			data.hasMoreLogs = false;
		} else {
			data.hasMoreLogs = true;
		}

		ui.refreshContent();
		ui.refreshEmptyLogsButton();
	};

	ui.setProcessActive = function( active ) {
		var old = data.processActive;

		data.processActive = !! active;

		if ( old === data.processActive ) {
			return;
		}

		if ( data.processActive ) {
			wp.heartbeat.interval( 'fast' );
			$( ui.selectors.startButton ).prop( 'disabled', true );
		} else {
			wp.heartbeat.interval( 'default' );
			$( ui.selectors.startButton ).prop( 'disabled', false );
		}

		ui.refreshEmptyLogsButton();
	};

	ui.refreshEmptyLogsButton = function() {
		if ( ! $( ui.selectors.emptyLogsButton ).length ) {
			return;
		}

		if ( Object.keys( data.processInfo ).length && ! data.processActive ) {
			$( ui.selectors.emptyLogsButton ).show();
		} else {
			$( ui.selectors.emptyLogsButton ).hide();
		}
	};

	ui.refreshContent = function() {
		if ( ! ui.template ) {
			ui.template = wp.template( 'background-process-info' );
		}

		$( ui.selectors.progress ).html( ui.template( data ) );
	};

	ui.init();
}( wpBackgroundProcessingUI, jQuery, wp ) );
