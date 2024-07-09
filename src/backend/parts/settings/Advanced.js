/**
 * WordPress dependencies.
 */
import {
	__,
	sprintf
} from '@wordpress/i18n';

import apiFetch from '@wordpress/api-fetch';

import {
	BaseControl,
	Button,
	ExternalLink,
	Icon,
	Panel,
	PanelRow,
	TextControl
} from '@wordpress/components';

import {
	useEffect,
	useState
} from '@wordpress/element';

import {
	useDispatch,
	useSelect
} from '@wordpress/data';

const Advanced = () => {
	const settings = useSelect( ( select ) => select( 'hyve' ).getSettings() );
	const {
		setHasAPI,
		setSetting
	} = useDispatch( 'hyve' );

	const { createNotice } = useDispatch( 'core/notices' );

	const [ isSaving, setIsSaving ] = useState( false );
	const [ license, setLicense ] = useState( window.hyve?.license );
	const [ licenseKey, setLicenseKey ] = useState( '' );

	useEffect( () => {
		const statuses = [ 'valid', 'active_expired' ];

		if ( license.key && ( statuses.includes( license.valid ) ) ) {
			setLicenseKey( license.key );
		}
	}, [ license ]);

	const onSave = async() => {
		setIsSaving( true );

		try {
			const response = await apiFetch({
				path: `${ window.hyve.api }/settings`,
				method: 'POST',
				data: {
					data: settings
				}
			});

			if ( response.error ) {
				throw new Error( response.error );
			}

			if ( settings.api_key ) {
				setHasAPI( true );
			} else {
				setHasAPI( false );
			}

			createNotice(
				'success',
				__( 'Settings saved.', 'hyve' ),
				{
					type: 'snackbar',
					isDismissible: true
				});
		} catch ( error ) {
			createNotice(
				'error',
				error,
				{
					type: 'snackbar',
					isDismissible: true
				});
		}

		setIsSaving( false );
	};

	const onSaveLicense = async( data ) => {
		setIsSaving( true );

		try {
			const response = await apiFetch({
				path: `${ window.hyve.api }/license`,
				method: 'POST',
				data: {
					data
				}
			});

			if ( response.error ) {
				throw new Error( response.error );
			}

			createNotice(
				response.success ? 'success' : 'error',
				response.message,
				{
					isDismissible: true,
					type: 'snackbar'
				}
			);

			if ( response?.success && response.license && 'free' !== response.license.key ) {
				setLicense( response.license );
				setLicenseKey( response.license.key );
			} else {
				setLicense({});
				setLicenseKey( '' );
			}

			window.location = window.location.href + '&nav=advanced';
		} catch ( error ) {
			createNotice(
				'error',
				error,
				{
					type: 'snackbar',
					isDismissible: true
				});
		}
		setIsSaving( false );
	};

	const isValid = 'valid' === license?.valid || 'valid' === license?.license;

	return (
		<div className="col-span-6 xl:col-span-4">
			<Panel
				header={ __( 'License', 'hyve' ) }
			>
				<PanelRow>
					<BaseControl
						help={ __( 'Enter your license from ThemeIsle purchase history in order to get plugin updates.', 'hyve' ) }
					>
						<TextControl
							type="text"
							value={ isValid ? '******************************' + licenseKey.slice( -5 ) : licenseKey }
							placeholder={ __( 'Enter License Key', 'hyve' ) }
							disabled={ isSaving || isValid }
							onChange={ ( newValue ) => setLicenseKey( newValue ) }
						/>
					</BaseControl>

					{ isValid && (
						<div>
							<p className="mb-2">
								<Icon icon="yes" className="text-green-500" />
								{
									license.expiration ?
										sprintf( __( 'Valid - Expires %s', 'hyve' ), license.expiration ) :
										__( 'Valid', 'hyve' )
								}
							</p>
						</div>
					)}

					{ 'active_expired' === license?.valid && (
						<>
							<p>{ __( 'License Key has expired. In order to continue receiving support and software updates you must renew your license key.', 'hyve' ) }</p>

							<ExternalLink href={ `https://store.themeisle.com?license=${ licenseKey }` } className="flex mb-2 items-centertext-sm text-blue-600">
								{ __( 'Renew License', 'hyve' ) }
							</ExternalLink>
						</>
					)}

					{ ! isValid && (
						<ExternalLink href="https://store.themeisle.com/purchase-history" className="flex mb-2 items-centertext-sm text-blue-600">
							{ __( 'Get license from Purchase History', 'hyve' ) }
						</ExternalLink>
					) }

					<Button
						variant={ isValid ? 'secondary' : 'primary' }
						isBusy={ isSaving }
						disabled={ isSaving }
						onClick={ () => onSaveLicense({
							action: isValid ? 'deactivate' : 'activate',
							key: licenseKey
						}) }
					>
						{ isValid ? __( 'Deactivate', 'hyve' ) : __( 'Activate', 'hyve' ) }
					</Button>
				</PanelRow>
			</Panel>

			<br/>

			<Panel
				header={ __( 'API Key', 'hyve' ) }
			>
				<PanelRow>
					<BaseControl
						help={ __( 'This plugin requires an OpenAI API key to function properly.', 'hyve' ) }
					>
						<TextControl
							label={ __( 'API Key', 'hyve' ) }
							type="password"
							value={ settings.api_key || '' }
							disabled={ isSaving }
							onChange={ ( newValue ) => setSetting( 'api_key', newValue ) }
						/>
					</BaseControl>

					<ExternalLink href="https://platform.openai.com/api-keys" className="flex mb-2 items-centertext-sm text-blue-600">
						{ __( 'Get an API key', 'hyve' ) }
					</ExternalLink>

					<Button
						variant="primary"
						isBusy={ isSaving }
						disabled={ isSaving }
						className="mt-2"
						onClick={ onSave }
					>
						{ __( 'Save', 'hyve' ) }
					</Button>
				</PanelRow>
			</Panel>
		</div>
	);
};

export default Advanced;
