const { registerBlockType } = wp.blocks;
const { InnerBlocks, InspectorControls } = wp.blockEditor;
const { PanelBody, SelectControl, Button } = wp.components;
const { __ } = wp.i18n;

const ALLOWED_BLOCKS = [
	'custom-block-plugin/hero-slide',
];

const SLIDER_TEMPLATE = [
	['custom-block-plugin/hero-slide']
];

// Custom Hero Block
registerBlockType("custom-block-plugin/hero-slider", {
	title: "Hero Slider",
	icon: "shield",
	category: "common",
	version: "0.1.1",
	attributes: {
		imageSize: {
			type: "string",
			default: "cover"
		}
	},

	edit: (props) => {
		const {
			attributes: { imageSize },
			setAttributes,
		} = props;

		return (
			<section className="hero-general-section">
				<InspectorControls>
					<PanelBody title="Slider Settings">
						<SelectControl
							label="Image Fit"
							value={imageSize}
							options={[
								{ label: 'Cover', value: 'cover' },
								{ label: 'Contain', value: 'contain' },
							]}
							onChange={(value) => setAttributes({ imageSize: value })}
						/>
					</PanelBody>
				</InspectorControls>

				<div className={`hero-slider-container ${imageSize === 'contain' ? 'hero-general-contained-image' : ''}`}>
					<InnerBlocks
						allowedBlocks={ALLOWED_BLOCKS}
						template={SLIDER_TEMPLATE}
						templateLock={false}
					/>
				</div>
			</section>
		);
	},

	save: (props) => {
		const {
			attributes: { imageSize },
		} = props;

		return (
			<section className="hero-general-section">
				<div className={`hero-slider-container ${imageSize === 'contain' ? 'hero-general-contained-image' : ''}`}>
					<InnerBlocks.Content />
				</div>
			</section>
		);
	},
});